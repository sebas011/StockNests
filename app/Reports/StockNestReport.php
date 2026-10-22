<?php

namespace App\Reports;

use App\Domain\Access;
use App\Domain\Decimal;
use App\Domain\OfficeMonthlyStatistics;
use App\Domain\OfficeWorkflow;
use App\Domain\WorkflowService;
use App\Models\AnnualProjection;
use App\Models\AuditLog;
use App\Models\BudgetAllocation;
use App\Models\FinancialEntry;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\ItemRequest;
use App\Models\ItemRequestLine;
use App\Models\ProjectionLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockBalance;
use App\Models\StockIssuance;
use App\Models\StockIssuanceLine;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Bounded database batches shared by all export formats. */
final class StockNestReport
{
    private const FILTERS = [
        AnnualProjection::class => ['fiscal_year_id', 'department_id', 'status'],
        BudgetAllocation::class => ['fiscal_year_id'],
        ItemRequest::class => ['fiscal_year_id', 'department_id', 'status'],
        PurchaseOrder::class => ['fiscal_year_id', 'department_id', 'supplier_id', 'status'],
        StockBalance::class => ['warehouse_id', 'item_id'],
        StockIssuance::class => ['warehouse_id'],
        InventoryCount::class => ['fiscal_year_id', 'warehouse_id', 'status'],
        AuditLog::class => [],
    ];

    public function __construct(private Request $request, private WorkflowService $workflow) {}

    /** @return iterable<int, array<string, mixed>> */
    public function rows(string $report): iterable
    {
        return match ($report) {
            'allowance' => $this->allowanceRows(),
            'monthly-items' => $this->monthlyRows(),
            'annual-plans' => $this->lines(ProjectionLine::class, 'projection', true)
                ->with('projection.fund', 'projection.fiscalYear', 'projection.department', 'item')->lazyById(200)
                ->map(fn ($l) => ['reference' => $l->projection->reference, 'fund' => $l->projection->fund->code, 'year' => $l->projection->fiscalYear->year, 'department' => $l->projection->department->name, 'original_projection_id' => $l->projection->original_projection_id ?? $l->projection->id, 'revision' => $l->projection->revision, 'status' => $l->projection->status, 'item' => $l->item->name, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'total' => $l->total]),
            'budgets' => $this->apply(BudgetAllocation::query())->with('fund', 'fiscalYear')->lazyById(200)->map(function ($allocation) {
                $plans = $this->apply(AnnualProjection::query(), true)->where('funding_source_id', $allocation->funding_source_id)->where('fiscal_year_id', $allocation->fiscal_year_id)->where('status', 'approved');
                $latest = (clone $plans)->selectRaw('COALESCE(original_projection_id, id) AS root_id, MAX(revision) AS newest')->groupByRaw('COALESCE(original_projection_id, id)');
                $revised = (clone $plans)->joinSub($latest, 'latest_plan', function ($join): void {
                    $join->on(DB::raw('COALESCE(annual_projections.original_projection_id, annual_projections.id)'), '=', 'latest_plan.root_id')->on('annual_projections.revision', '=', 'latest_plan.newest');
                })->sum('annual_projections.total');

                return ['fund' => $allocation->fund->code, 'year' => $allocation->fiscalYear->year, 'original_plan' => Decimal::add((string) (clone $plans)->whereNull('original_projection_id')->sum('total'), '0'), 'revised_plan' => Decimal::add((string) $revised, '0')] + $this->workflow->budget($allocation);
            }),
            'requests' => $this->lines(ItemRequestLine::class, 'request', true)->with('request.fund', 'request.department', 'request.creator', 'item')->lazyById(200)
                ->map(fn ($l) => ['reference' => $l->request->reference, 'fund' => $l->request->fund->code, 'department' => $l->request->department->name, 'requester' => $l->request->creator->name, 'date' => $l->request->created_at->toIso8601String(), 'status' => $l->request->status, 'item' => $l->item->name, 'requested' => $l->quantity, 'approved' => $l->approved_quantity, 'fulfilled' => $l->fulfilled_quantity, 'unplanned' => $l->is_unplanned ? 'Yes' : 'No'] + (OfficeWorkflow::enabled() ? ['channel' => $l->request->channel, 'supply_received' => $l->request->channel === 'supply' ? $l->received_quantity : '0.0000', 'outside_received' => $l->request->channel === 'cash' ? $l->received_quantity : '0.0000', 'submitted_at' => $l->request->submitted_at?->toIso8601String()] : [])),
            'purchases' => $this->lines(PurchaseOrderLine::class, 'order', true)->with('order.fund', 'order.supplier', 'order.request', 'item')->lazyById(200)
                ->map(fn ($l) => ['reference' => $l->order->reference, 'request' => $l->order->request->reference, 'fund' => $l->order->fund->code, 'supplier' => $l->order->supplier->name, 'status' => $l->order->status, 'item' => $l->item->name, 'ordered' => $l->quantity, 'received' => $l->received_quantity, 'unit_price' => $l->unit_price, 'line_total' => $l->total, 'order_commitment' => $l->order->committed_amount, 'order_expenditure' => $l->order->spent_amount] + (OfficeWorkflow::enabled() ? ['cash_authority_reference' => $l->order->authorization_reference] : [])),
            'stock' => $this->apply(StockBalance::query())->with('fund', 'warehouse', 'item')->withSum(['reservations as reserved_quantity' => fn ($q) => $q->where('status', 'active')], 'quantity')->lazyById(200)
                ->map(fn ($b) => ['fund' => $b->fund->code, 'location' => $b->warehouse->name, 'sku' => $b->item->sku, 'item' => $b->item->name, 'quantity' => $b->quantity, 'reserved' => Decimal::add((string) ($b->reserved_quantity ?? '0'), '0', 4), 'reorder_point' => $b->item->reorder_point, 'low_stock' => Decimal::cmp($b->quantity, $b->item->reorder_point) <= 0 ? 'Yes' : 'No', 'estimated_value' => Decimal::mul($b->quantity, $b->item->standard_cost)]),
            'movements' => $this->movementRows(),
            'issuances' => $this->lines(StockIssuanceLine::class, 'issuance')->whereHas('issuance.request', fn ($q) => $this->department($q))->with('issuance.request', 'issuance.warehouse', 'item')->lazyById(200)
                ->map(fn ($l) => ['reference' => $l->issuance->reference, 'request' => $l->issuance->request->reference, 'fund_id' => $l->issuance->funding_source_id, 'location' => $l->issuance->warehouse->name, 'recipient' => $l->issuance->recipient, 'date' => $l->issuance->created_at->toIso8601String(), 'item' => $l->item->name, 'issued' => $l->quantity, 'returned' => $l->returned_quantity]),
            'counts' => $this->lines(InventoryCountLine::class, 'count')->with('count.fund', 'count.warehouse', 'count.counter', 'item')->lazyById(200)
                ->map(fn ($l) => ['reference' => $l->count->reference, 'fund' => $l->count->fund->code, 'location' => $l->count->warehouse->name, 'month' => $l->count->count_month, 'cutoff' => $l->count->cutoff_at->toIso8601String(), 'counter' => $l->count->counter->name, 'status' => $l->count->status, 'item' => $l->item->name, 'system_quantity' => $l->system_quantity, 'physical_quantity' => $l->physical_quantity, 'variance' => $l->variance, 'variance_reason' => $l->variance_reason]),
            'expenditures' => $this->financialRows(),
            'audit' => $this->apply(AuditLog::query())->with('user', 'fund')->lazyById(200)
                ->map(fn ($r) => ['timestamp' => $r->created_at->toIso8601String(), 'actor' => $r->user?->name, 'fund' => $r->fund?->code, 'action' => $r->action, 'record_type' => class_basename($r->subject_type), 'record_id' => $r->subject_id, 'reason' => $r->reason, 'before' => json_encode($r->before_values, JSON_THROW_ON_ERROR), 'after' => json_encode($r->after_values, JSON_THROW_ON_ERROR)]),
            default => abort(404),
        };
    }

    /** @return iterable<int, array<string, mixed>> */
    private function allowanceRows(): iterable
    {
        abort_unless(OfficeWorkflow::enabled(), 404);
        foreach (OfficeWorkflow::allowances($this->request->user(), $this->optionalInteger('fund_id'), $this->optionalInteger('fiscal_year_id')) as $row) {
            if ($this->matchesItem($row) && (! $this->request->filled('year') || (int) $row['year'] === $this->request->integer('year'))) {
                yield $row;
            }
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function monthlyRows(): iterable
    {
        abort_unless(OfficeWorkflow::enabled(), 404);
        foreach (OfficeMonthlyStatistics::iterate(
            $this->request->user(), $this->optionalInteger('fund_id'), $this->optionalInteger('year'),
            $this->request->filled('date_from') ? $this->request->string('date_from')->toString() : null,
            $this->request->filled('date_to') ? $this->request->string('date_to')->toString() : null,
            $this->optionalInteger('fiscal_year_id'),
        ) as $row) {
            if ($this->matchesItem($row)) {
                yield $row;
            }
        }
    }

    private function optionalInteger(string $key): ?int
    {
        return $this->request->filled($key) ? $this->request->integer($key) : null;
    }

    /** @param array<string, mixed> $row */
    private function matchesItem(array $row): bool
    {
        return (! $this->request->filled('item_id') || (int) $row['item_id'] === $this->request->integer('item_id'))
            && (! $this->request->filled('q') || str_contains(mb_strtolower($row['item'].' '.$row['sku']), mb_strtolower($this->request->string('q')->toString())));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function apply(Builder $query, bool $department = false, bool $dates = true): Builder
    {
        Access::scope($query, $this->request->user(), $department);
        if ($this->request->filled('fund_id')) {
            $query->where('funding_source_id', $this->request->integer('fund_id'));
        }
        if ($dates) {
            $this->dates($query);
        }
        foreach (self::FILTERS[$query->getModel()::class] ?? [] as $column) {
            if ($this->request->filled($column)) {
                $query->where($column, $this->request->input($column));
            }
        }
        if ($this->request->filled('q') && in_array($query->getModel()::class, [AnnualProjection::class, ItemRequest::class, PurchaseOrder::class, StockIssuance::class, InventoryCount::class], true)) {
            $query->where('reference', 'like', '%'.$this->request->input('q').'%');
        }

        return $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return Builder<TModel>
     */
    private function lines(string $class, string $relationship, bool $department = false): Builder
    {
        return $class::query()->whereHas($relationship, fn ($q) => $this->apply($q, $department));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function department(Builder $query): void
    {
        if (Access::departmentLimited($this->request->user())) {
            $query->where('department_id', $this->request->user()->department_id);
        }
        if ($this->request->filled('department_id')) {
            $query->where('department_id', $this->request->integer('department_id'));
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function dates(Builder $query): void
    {
        foreach (['date_from' => '>=', 'date_to' => '<='] as $input => $operator) {
            if ($this->request->filled($input)) {
                $query->whereDate('created_at', $operator, $this->request->input($input));
            }
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function movementRows(): iterable
    {
        $query = StockMovement::whereHas('balance', fn ($q) => $this->apply($q, false, false))->with('balance.fund', 'balance.item', 'balance.warehouse', 'user');
        $this->dates($query);

        return $query->lazyById(200)->map(fn ($m) => ['timestamp' => $m->created_at->toIso8601String(), 'fund' => $m->balance->fund->code, 'location' => $m->balance->warehouse->name, 'item' => $m->balance->item->name, 'kind' => $m->kind, 'quantity_delta' => $m->quantity_delta, 'balance_after' => $m->balance_after, 'responsible_user' => $m->user->name, 'source' => class_basename($m->source_type).' #'.$m->source_id, 'remarks' => $m->remarks]);
    }

    /** @return iterable<int, array<string, mixed>> */
    private function financialRows(): iterable
    {
        $query = FinancialEntry::whereHas('allocation', fn ($q) => $this->apply($q, false, false))->whereHas('order', fn ($q) => $this->department($q))->with('allocation.fund', 'allocation.fiscalYear', 'order');
        $this->dates($query);

        return $query->lazyById(200)->map(fn ($e) => ['timestamp' => $e->created_at->toIso8601String(), 'fund' => $e->allocation->fund->code, 'year' => $e->allocation->fiscalYear->year, 'purchase_order' => $e->order->reference, 'kind' => $e->kind, 'commitment_change' => $e->commitment_delta, 'actual_expenditure' => $e->expenditure_delta, 'document_reference' => $e->reference]);
    }
}
