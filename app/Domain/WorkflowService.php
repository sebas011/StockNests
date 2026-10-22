<?php

namespace App\Domain;

use App\Events\WorkflowChanged;
use App\Models\AnnualProjection;
use App\Models\ApprovalDecision;
use App\Models\AuditLog;
use App\Models\BudgetAdjustment;
use App\Models\BudgetAllocation;
use App\Models\Department;
use App\Models\FinancialEntry;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\GoodsReceipt;
use App\Models\IdempotentCommand;
use App\Models\InventoryCount;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemRequest;
use App\Models\ItemRequestLine;
use App\Models\ProjectionLine;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockIssuance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplyReceipt;
use App\Models\SystemSetting;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** All financial and physical changes are performed through this transaction boundary. */
final class WorkflowService
{
    public const PERMISSIONS = ['dashboard.view', 'master-data.manage', 'budgets.manage', 'budget.approve', 'fund.approve', 'requests.department-approve', 'fiscal.reopen', 'requests.create', 'requests.approve', 'procurement.manage', 'inventory.manage', 'counts.approve', 'reports.view', 'audit.view', 'users.manage'];

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: int|string|null, type: class-string<Model>, reference: string}
     */
    public function execute(User $user, string $operation, array $data, string $key): array
    {
        unset($data['idempotency_key']);
        if (OfficeWorkflow::enabled()) {
            $this->ensure(! in_array($operation, ['allocation.decide', 'projection.submit', 'projection.decide', 'request.decide', 'purchase.approve', 'count.decide', 'reservation.create', 'transfer.create', 'issuance.create', 'issuance.return'], true), 'This institutional workflow is not used by the office tracking system.');
            if (in_array($operation, ['projection.save', 'request.save'], true)) {
                $data['department_id'] = OfficeWorkflow::departmentId();
            }
        }
        /** @param array<array-key, mixed> $value
         * @return array<array-key, mixed>
         */
        $canonical = function (array $value) use (&$canonical): array {
            foreach ($value as &$entry) {
                if (is_array($entry)) {
                    $entry = $canonical($entry);
                }
            } unset($entry);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };
        $hash = hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $operation, $data, $key, $hash) {
            DB::table('idempotent_commands')->insertOrIgnore(['key' => $key, 'user_id' => $user->id, 'operation' => $operation, 'request_hash' => $hash, 'created_at' => now()]);
            $command = IdempotentCommand::where('key', $key)->lockForUpdate()->firstOrFail();
            $this->ensure((int) $command->user_id === $user->id && $command->operation === $operation && hash_equals($command->request_hash, $hash), 'This transaction key was already used for a different operation.');
            if ($command->result !== null) {
                $result = $this->replayResult($command->result);
                $this->authorizeReplay($user, $operation, $result);

                return $result;
            }
            $record = match ($operation) {
                'master.save' => $this->master($user, $data),
                'fiscal-year.reopen' => $this->reopenYear($user, $data),
                'allocation.create' => $this->allocation($user, $data),
                'allocation.decide' => $this->allocationDecision($user, $data),
                'projection.save' => $this->projection($user, $data),
                'projection.submit' => $this->submit($user, AnnualProjection::class, $data, 'budgets.manage'),
                'projection.decide' => $this->projectionDecision($user, $data),
                'request.save' => $this->requisition($user, $data),
                'request.submit' => $this->submit($user, ItemRequest::class, $data, 'requests.create'),
                'request.decide' => $this->requestDecision($user, $data),
                'request.cancel' => $this->cancelRequest($user, $data),
                'purchase.create' => $this->purchase($user, $data),
                'purchase.approve' => $this->approvePurchase($user, $data),
                'purchase.cancel' => $this->cancelPurchase($user, $data),
                'purchase.spend' => $this->spend($user, $data),
                'receipt.create' => $this->receive($user, $data),
                'supply.receive' => $this->receiveSupply($user, $data),
                'stock.consume' => $this->consume($user, $data),
                'issuance.create' => $this->issue($user, $data),
                'issuance.return' => $this->returnStock($user, $data),
                'reservation.create' => $this->reserve($user, $data),
                'transfer.create' => $this->transfer($user, $data),
                'count.create' => $this->count($user, $data),
                'count.encode' => $this->encodeCount($user, $data),
                'count.submit' => $this->submitCount($user, $data),
                'count.decide' => $this->decideCount($user, $data),
                'count.reconcile' => $this->reconcile($user, $data),
                default => throw ValidationException::withMessages(['operation' => 'Unknown operation.']),
            };
            $result = ['id' => $record->getKey(), 'type' => $record::class, 'reference' => $record->reference ?? $record->name ?? (string) $record->getKey()];
            $command->update(['result' => $result]);

            return $result;
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{id: int|string|null, type: class-string<Model>, reference: string}
     */
    private function replayResult(array $result): array
    {
        $this->ensure(array_key_exists('id', $result) && (is_int($result['id']) || is_string($result['id']) || $result['id'] === null)
            && isset($result['type']) && is_string($result['type']) && is_subclass_of($result['type'], Model::class)
            && isset($result['reference']) && is_string($result['reference']), 'The saved transaction result is invalid.');

        return ['id' => $result['id'], 'type' => $result['type'], 'reference' => $result['reference']];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, array<array-key, mixed>>
     */
    private function commandLines(array $data): array
    {
        $lines = $data['lines'] ?? [];
        $this->ensure(is_array($lines), 'Workflow lines must be an array.', 'lines');
        $result = [];
        foreach ($lines as $key => $line) {
            $this->ensure(is_array($line), 'Each workflow line must be an array.', 'lines');
            $result[$key] = $line;
        }

        return $result;
    }

    /** @param array{id: int|string|null, type: class-string<Model>, reference: string} $result */
    private function authorizeReplay(User $user, string $operation, array $result): void
    {
        $permission = match ($operation) {
            'master.save' => 'master-data.manage',
            'fiscal-year.reopen' => 'fiscal.reopen',
            'allocation.create', 'projection.save', 'projection.submit', 'purchase.spend' => 'budgets.manage',
            'allocation.decide', 'projection.decide' => 'budget.approve',
            'purchase.approve' => 'fund.approve',
            'request.decide' => 'requests.approve',
            'request.save', 'request.submit', 'request.cancel' => 'requests.create',
            'purchase.create', 'purchase.cancel' => 'procurement.manage', 'count.decide' => 'counts.approve',
            default => 'inventory.manage',
        };
        $record = $result['type']::findOrFail($result['id']);
        $fund = match (true) {
            $record instanceof FundingSource => $record->getKey(), $record instanceof BudgetAdjustment => $record->allocation->funding_source_id, $record instanceof StockReservation => $record->balance->funding_source_id, default => $record->getAttribute('funding_source_id')
        };
        Access::assert($user, $permission, $fund ? (int) $fund : null, $record->getAttribute('department_id') ? (int) $record->getAttribute('department_id') : null);
        Gate::forUser($user)->authorize('view', $record);
    }

    /** @phpstan-assert true $condition */
    private function ensure(bool $condition, string $message, string $field = 'workflow'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function activeYear(int $id): FiscalYear
    {
        $year = FiscalYear::whereKey($id)->lockForUpdate()->firstOrFail();
        $this->ensure($year->status === 'open', 'The fiscal year is closed. Changes are not permitted.');

        return $year;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     */
    private function record(User $user, string $class, int $id, string $permission, bool $approve = false): Model
    {
        $record = (new $class)->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
        Access::assert($user, $permission, $record->getAttribute('funding_source_id') ? (int) $record->getAttribute('funding_source_id') : null, $record->getAttribute('department_id') ? (int) $record->getAttribute('department_id') : null);
        Gate::forUser($user)->authorize($approve ? 'approve' : 'view', $record);
        if ($record->getAttribute('fiscal_year_id')) {
            $this->activeYear((int) $record->getAttribute('fiscal_year_id'));
        }

        return $record;
    }

    /** @param array<string, mixed> $before */
    private function audit(User $user, Model $record, string $action, array $before = [], ?string $reason = null, ?int $fund = null): void
    {
        $fund ??= $record->getAttribute('funding_source_id') ? (int) $record->getAttribute('funding_source_id') : null;
        $fresh = $record->fresh();
        if (method_exists($fresh, 'lines')) {
            $fresh->load('lines');
        }
        AuditLog::create(['user_id' => $user->id, 'funding_source_id' => $fund, 'action' => $action, 'subject_type' => $record::class, 'subject_id' => $record->getKey(), 'before_values' => $before, 'after_values' => $fresh->toArray(), 'reason' => $reason, 'created_at' => now()]);
        if ($fund && class_exists(WorkflowChanged::class)) {
            event(new WorkflowChanged($fund, $user->id, $action, $record::class, (int) $record->getKey(), (string) ($record->reference ?? $record->getKey())));
        }
    }

    private function decision(User $user, Model $record, string $decision, ?string $comment, int $stage = 1, ?int $fund = null): void
    {
        $this->ensure(in_array($decision, ['approved', 'rejected', 'returned'], true), 'Invalid decision.');
        $this->ensure($decision === 'approved' || filled($comment), 'A comment is required for rejection or revision.', 'comment');
        ApprovalDecision::create(['user_id' => $user->id, 'funding_source_id' => $fund ?? $record->getAttribute('funding_source_id'), 'subject_type' => $record::class, 'subject_id' => $record->getKey(), 'stage' => $stage, 'decision' => $decision, 'comment' => $comment, 'created_at' => now()]);
    }

    /** @param array<string, mixed> $data */
    private function master(User $user, array $data): Model
    {
        Access::assert($user, 'master-data.manage');
        $this->ensure(! OfficeWorkflow::enabled() || $data['kind'] !== 'department', 'This system tracks only the Office of the Vice President for Academic Affairs.');
        $classes = ['fund' => FundingSource::class, 'department' => Department::class, 'fiscal-year' => FiscalYear::class, 'category' => ItemCategory::class, 'unit' => UnitOfMeasure::class, 'warehouse' => Warehouse::class, 'supplier' => Supplier::class, 'item' => Item::class];
        $class = $classes[$data['kind']];
        $allowed = match ($data['kind']) {
            'item' => ['sku', 'name', 'item_category_id', 'unit_of_measure_id', 'description', 'specifications', 'item_type', 'minimum_stock', 'reorder_point', 'standard_cost', 'supplier_id', 'is_active'],
            'fiscal-year' => ['year', 'starts_on', 'ends_on', 'status'],
            'fund' => ['code', 'name', 'accounting_reference', 'budget_control', 'is_active'],
            'supplier' => ['code', 'name', 'email', 'address', 'is_active'],
            default => ['code', 'name', 'is_active'],
        };
        $attributes = array_intersect_key($data, array_flip($allowed));
        $record = isset($data['id']) ? $class::whereKey($data['id'])->lockForUpdate()->firstOrFail() : new $class;
        if ($record instanceof FundingSource && $record->exists && ! $user->fundingSources()->whereKey($record->getKey())->exists()) {
            throw new AuthorizationException('You cannot configure this funding source.');
        }
        $before = $record->toArray();
        if ($record instanceof FiscalYear && $record->exists && $record->status === 'closed') {
            $this->ensure(($attributes['status'] ?? 'closed') === 'closed', 'Use the controlled fiscal-year reopen action and provide a reason.');
        }
        $record->fill($attributes)->save();
        if ($record instanceof FundingSource && ! isset($data['id'])) {
            $record->users()->attach($user->id);
        }
        $this->audit($user, $record, 'master.saved', $before);

        return $record;
    }

    /** @param array<string, mixed> $data */
    private function reopenYear(User $user, array $data): FiscalYear
    {
        Access::assert($user, 'fiscal.reopen');
        $this->ensure(filled($data['reason'] ?? null), 'A reason is required to reopen a fiscal year.', 'reason');
        $year = FiscalYear::whereKey($data['id'])->lockForUpdate()->firstOrFail();
        $this->ensure($year->status === 'closed', 'Only a closed fiscal year may be reopened.');
        $before = $year->toArray();
        $year->update(['status' => 'open']);
        $this->audit($user, $year, 'fiscal-year.reopened', $before, $data['reason']);

        return $year;
    }

    /** @param array<string, mixed> $data */
    private function allocation(User $user, array $data): BudgetAdjustment
    {
        Access::assert($user, 'budgets.manage', (int) $data['funding_source_id']);
        $this->activeYear((int) $data['fiscal_year_id']);
        $allocation = BudgetAllocation::firstOrCreate(['funding_source_id' => $data['funding_source_id'], 'fiscal_year_id' => $data['fiscal_year_id']], ['authorized_amount' => '0']);
        $allocation = BudgetAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
        $record = BudgetAdjustment::create(['budget_allocation_id' => $allocation->id, 'created_by' => $user->id, 'amount' => $data['amount'], 'reason' => $data['reason'], 'status' => 'submitted']);
        if (OfficeWorkflow::enabled()) {
            $new = Decimal::add($allocation->authorized_amount, $data['amount'], 2);
            $totals = $this->budget($allocation);
            $this->ensure(Decimal::cmp($new, Decimal::add($totals['committed'], $totals['spent'], 2), 2) >= 0, 'The authorized budget cannot be reduced below existing commitments and expenditure.');
            $allocation->update(['authorized_amount' => $new]);
            $record->update(['status' => 'approved', 'approved_by' => $user->id]);
            $this->audit($user, $record, 'allocation.recorded', [], $data['reason'], (int) $allocation->funding_source_id);

            return $record;
        }
        $this->audit($user, $record, 'allocation.submitted', [], $data['reason'], (int) $allocation->funding_source_id);

        return $record;
    }

    /** @param array<string, mixed> $data */
    private function allocationDecision(User $user, array $data): BudgetAdjustment
    {
        $adjustment = BudgetAdjustment::whereKey($data['id'])->lockForUpdate()->firstOrFail();
        $allocation = BudgetAllocation::whereKey($adjustment->budget_allocation_id)->lockForUpdate()->firstOrFail();
        Access::assert($user, 'budget.approve', (int) $allocation->funding_source_id);
        Gate::forUser($user)->authorize('approve', $adjustment);
        $this->activeYear((int) $allocation->fiscal_year_id);
        $this->ensure($adjustment->status === 'submitted', 'This allocation change has already been reviewed.');
        $this->decision($user, $adjustment, $data['decision'], $data['comment'] ?? null, fund: (int) $allocation->funding_source_id);
        if ($data['decision'] === 'approved') {
            $new = Decimal::add($allocation->authorized_amount, $adjustment->amount, 2);
            $totals = $this->budget($allocation);
            $this->ensure(Decimal::cmp($new, Decimal::add($totals['committed'], $totals['spent'], 2), 2) >= 0, 'The allocation cannot be reduced below existing commitments and expenditure.');
            $allocation->update(['authorized_amount' => $new]);
        }
        $before = $adjustment->toArray();
        $adjustment->update(['status' => $data['decision'], 'approved_by' => $user->id]);
        $this->audit($user, $adjustment, 'allocation.'.$data['decision'], $before, $data['comment'] ?? null, (int) $allocation->funding_source_id);

        return $adjustment;
    }

    /** @return array{allocation: numeric-string, committed: numeric-string, spent: numeric-string, available: numeric-string} */
    public function budget(BudgetAllocation $allocation): array
    {
        $totals = FinancialEntry::where('budget_allocation_id', $allocation->id)
            ->selectRaw('COALESCE(SUM(commitment_delta), 0) AS committed, COALESCE(SUM(expenditure_delta), 0) AS spent')
            ->first();
        $committed = Decimal::add((string) $totals->getAttribute('committed'), '0', 2);
        $spent = Decimal::add((string) $totals->getAttribute('spent'), '0', 2);

        return ['allocation' => $allocation->authorized_amount, 'committed' => $committed, 'spent' => $spent, 'available' => Decimal::sub(Decimal::sub($allocation->authorized_amount, $committed, 2), $spent, 2)];
    }

    /** @param array<string, mixed> $data */
    private function projection(User $user, array $data): AnnualProjection
    {
        Access::assert($user, 'budgets.manage', (int) $data['funding_source_id'], (int) $data['department_id']);
        $this->activeYear((int) $data['fiscal_year_id']);
        $original = null;
        if (! empty($data['original_projection_id'])) {
            $original = $this->record($user, AnnualProjection::class, (int) $data['original_projection_id'], 'budgets.manage');
            $this->ensure($original->status === 'approved' && (int) $original->funding_source_id === (int) $data['funding_source_id'] && (int) $original->fiscal_year_id === (int) $data['fiscal_year_id'] && (int) $original->department_id === (int) $data['department_id'], 'Amendments must retain the original fiscal year, department, and fund.');
            $original = $original->original_projection_id ? AnnualProjection::whereKey($original->original_projection_id)->firstOrFail() : $original;
            $this->ensure(! AnnualProjection::where('original_projection_id', $original->id)->when(! empty($data['id']), fn ($q) => $q->where('id', '<>', $data['id']))->whereIn('status', ['draft', 'submitted', 'returned'])->exists(), 'Complete the pending amendment first.');
        }
        $lines = $this->costedLines($data['lines']);
        $projection = ! empty($data['id']) ? $this->record($user, AnnualProjection::class, (int) $data['id'], 'budgets.manage') : new AnnualProjection;
        $this->ensure(! $projection->exists || in_array($projection->status, ['draft', 'returned'], true), 'Approved or submitted projections cannot be edited. Create an amendment.');
        $before = $projection->exists ? $projection->load('lines')->toArray() : [];
        if ($projection->exists) {
            $projection->lines()->delete();
        }
        $revision = $projection->exists ? $projection->revision : ($original ? (int) AnnualProjection::where(fn ($q) => $q->whereKey($original->id)->orWhere('original_projection_id', $original->id))->max('revision') + 1 : 1);
        if (OfficeWorkflow::enabled()) {
            $this->ensure($original || ! AnnualProjection::where('funding_source_id', $data['funding_source_id'])->where('fiscal_year_id', $data['fiscal_year_id'])->where('department_id', $data['department_id'])->whereNull('original_projection_id')->where('status', 'approved')->exists(), 'An approved annual plan already exists. Record an approved amendment instead.');
            $requested = $this->supplyRequestedQuantities((int) $data['funding_source_id'], (int) $data['fiscal_year_id'], (int) $data['department_id']);
            $approved = collect($lines)->keyBy('item_id');
            foreach ($requested as $item => $quantity) {
                $this->ensure(Decimal::cmp($approved->get($item)['quantity'] ?? '0', $quantity) >= 0, 'The approved annual allowance cannot be reduced below quantities already submitted to Supply.', 'lines');
            }
        }
        $projection->fill(['reference' => $projection->reference ?? 'PRJ-'.Str::upper(Str::random(10)), 'funding_source_id' => $data['funding_source_id'], 'fiscal_year_id' => $data['fiscal_year_id'], 'department_id' => $data['department_id'], 'created_by' => $projection->created_by ?? $user->id, 'original_projection_id' => $original?->id, 'revision' => $revision, 'status' => OfficeWorkflow::enabled() ? 'approved' : 'draft', 'approved_at' => OfficeWorkflow::enabled() ? now() : null, 'total' => Decimal::sum(array_column($lines, 'total')), 'justification' => $data['justification']])->save();
        $projection->lines()->createMany($lines);
        $this->audit($user, $projection, 'projection.saved', $before);

        return $projection;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function costedLines(array $lines): array
    {
        $this->ensure(count($lines) > 0, 'At least one item is required.', 'lines');
        $seen = [];
        foreach ($lines as &$line) {
            $this->ensure(! in_array((int) $line['item_id'], $seen, true), 'An item may appear only once.', 'lines');
            $seen[] = (int) $line['item_id'];
            Item::whereKey($line['item_id'])->where('is_active', true)->firstOrFail();
            $this->ensure(Decimal::cmp((string) $line['quantity'], '0') > 0 && Decimal::cmp((string) $line['unit_price'], '0', 2) >= 0, 'Quantities must be positive and costs must be nonnegative.', 'lines');
            $line = array_intersect_key($line, array_flip(['item_id', 'quantity', 'unit_price', 'priority', 'planned_month', 'remarks']));
            $line['total'] = Decimal::mul($line['quantity'], $line['unit_price']);
        } unset($line);

        return $lines;
    }

    /**
     * @template TModel of AnnualProjection|ItemRequest
     *
     * @param  class-string<TModel>  $class
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    private function submit(User $user, string $class, array $data, string $permission): Model
    {
        $record = $this->record($user, $class, (int) $data['id'], $permission);
        if ($record instanceof ItemRequest) {
            $this->requestOwner($user, $record);
        }
        $this->ensure(in_array($record->status, ['draft', 'returned'], true), 'Only draft or returned records may be submitted.');
        $this->ensure($record->lines()->exists(), 'At least one item is required.');
        $before = $record->toArray();
        if (OfficeWorkflow::enabled() && $record instanceof ItemRequest) {
            $lines = $record->lines()->orderBy('item_id')->lockForUpdate()->get();
            if ($record->channel === 'supply') {
                $plan = OfficeWorkflow::currentPlans()->where('funding_source_id', $record->funding_source_id)->where('fiscal_year_id', $record->fiscal_year_id)->where('department_id', $record->department_id)->first();
                $this->ensure($plan !== null, 'Encode the externally approved annual items before submitting requests to Supply.');
                $approved = $plan->lines()->get()->keyBy('item_id');
                $requested = $this->supplyRequestedQuantities((int) $record->funding_source_id, (int) $record->fiscal_year_id, (int) $record->department_id);
                foreach ($lines as $line) {
                    $allowance = $approved->get($line->item_id)->quantity ?? '0';
                    $used = $requested[(int) $line->item_id] ?? '0';
                    $this->ensure(Decimal::cmp(Decimal::add($used, $line->quantity), $allowance) <= 0, 'This request exceeds the remaining approved annual quantity for an item.', 'lines');
                }
            }
            foreach ($lines as $line) {
                $line->update(['approved_quantity' => $line->quantity]);
            }
            $record->update(['status' => $record->channel === 'cash' ? 'approved' : 'submitted', 'submitted_at' => now()]);
            $this->audit($user, $record, 'request.submitted', $before);

            return $record;
        }
        $record->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->audit($user, $record, $class === ItemRequest::class ? 'request.submitted' : 'projection.submitted', $before);

        return $record;
    }

    /** @param array<string, mixed> $data */
    private function projectionDecision(User $user, array $data): AnnualProjection
    {
        $record = $this->record($user, AnnualProjection::class, (int) $data['id'], 'budget.approve', true);
        $this->ensure($record->status === 'submitted', 'Only submitted projections may be reviewed.');
        if ($data['decision'] === 'approved' && ! $record->original_projection_id) {
            $this->ensure(! AnnualProjection::where('funding_source_id', $record->getAttribute('funding_source_id'))->where('fiscal_year_id', $record->getAttribute('fiscal_year_id'))->where('department_id', $record->getAttribute('department_id'))->whereNull('original_projection_id')->where('status', 'approved')->where('id', '<>', $record->getKey())->exists(), 'An approved baseline already exists for this fund, year and department. Create an amendment.');
        }
        $this->decision($user, $record, $data['decision'], $data['comment'] ?? null);
        $before = $record->toArray();
        $record->update(['status' => $data['decision'], 'approved_at' => $data['decision'] === 'approved' ? now() : null]);
        $this->audit($user, $record, 'projection.'.$data['decision'], $before, $data['comment'] ?? null);

        return $record;
    }

    /** @param array<string, mixed> $data */
    private function requisition(User $user, array $data): ItemRequest
    {
        Access::assert($user, 'requests.create', (int) $data['funding_source_id'], (int) $data['department_id']);
        $this->activeYear((int) $data['fiscal_year_id']);
        $lines = $this->costedLines($data['lines']);
        $channel = $data['channel'] ?? 'supply';
        $this->ensure(in_array($channel, ['supply', 'cash'], true), 'Choose a Supply request or an outside cash purchase.', 'channel');
        $unplanned = false;
        $currentPlanIds = AnnualProjection::where('funding_source_id', $data['funding_source_id'])
            ->where('fiscal_year_id', $data['fiscal_year_id'])->where('department_id', $data['department_id'])
            ->where('status', 'approved')->get()
            ->groupBy(fn ($plan) => $plan->original_projection_id ?? $plan->id)
            ->map(fn ($versions) => $versions->sortByDesc('revision')->first()->id)->values();
        foreach ($lines as &$line) {
            $planned = ProjectionLine::whereIn('annual_projection_id', $currentPlanIds)->where('item_id', $line['item_id'])->exists();
            $line = array_intersect_key($line, array_flip(['item_id', 'quantity', 'unit_price']));
            $line['is_unplanned'] = ! $planned;
            $unplanned = $unplanned || ! $planned;
        } unset($line);
        $record = ! empty($data['id']) ? $this->record($user, ItemRequest::class, (int) $data['id'], 'requests.create') : new ItemRequest;
        if ($record->exists) {
            $this->requestOwner($user, $record);
        }
        $this->ensure(! $record->exists || in_array($record->status, ['draft', 'returned'], true), 'Only draft or returned requests may be edited.');
        $before = $record->exists ? $record->load('lines')->toArray() : [];
        if ($record->exists) {
            $record->lines()->delete();
        }
        $record->fill(['reference' => $record->reference ?? 'REQ-'.Str::upper(Str::random(10)), 'funding_source_id' => $data['funding_source_id'], 'fiscal_year_id' => $data['fiscal_year_id'], 'department_id' => $data['department_id'], 'created_by' => $record->created_by ?? $user->id, 'channel' => $channel, 'required_on' => $data['required_on'], 'purpose' => $data['purpose'], 'priority' => $data['priority'] ?? 'normal', 'status' => 'draft', 'approval_stage' => 0, 'is_unplanned' => $unplanned, 'estimated_total' => Decimal::sum(array_map(fn ($l) => Decimal::mul($l['quantity'], $l['unit_price']), $lines))])->save();
        $record->lines()->createMany($lines);
        $this->audit($user, $record, 'request.saved', $before);

        return $record;
    }

    /** @return array<int, numeric-string> */
    private function supplyRequestedQuantities(int $fund, int $year, int $department): array
    {
        $quantities = DB::table('item_request_lines')->join('item_requests', 'item_requests.id', '=', 'item_request_lines.item_request_id')
            ->where('item_requests.funding_source_id', $fund)->where('item_requests.fiscal_year_id', $year)->where('item_requests.department_id', $department)
            ->where('item_requests.channel', 'supply')->whereNotNull('item_requests.submitted_at')->whereNotIn('item_requests.status', ['draft', 'returned', 'rejected', 'canceled'])
            ->groupBy('item_request_lines.item_id')->selectRaw('item_request_lines.item_id, SUM(item_request_lines.quantity) AS requested')->get();
        $result = [];
        foreach ($quantities as $quantity) {
            $result[(int) $quantity->item_id] = Decimal::add((string) $quantity->requested, '0');
        }

        return $result;
    }

    /** @return list<string> */
    public function approvalRoles(): array
    {
        $roles = SystemSetting::whereKey('request_approval_roles')->first()?->getAttribute('value');
        if ($roles === null) {
            return ['Department Head', 'Fund Approver'];
        }
        if (! is_array($roles) || ! array_is_list($roles) || array_filter($roles, fn ($role) => ! is_string($role))) {
            throw ValidationException::withMessages(['workflow' => 'The configured request approval roles are invalid.']);
        }

        return $roles;
    }

    /**
     * @param  Collection<int, ApprovalDecision>|null  $history
     * @param  list<string>|null  $roles
     */
    public function canReviewRequestStage(User $user, ItemRequest $record, ?Collection $history = null, ?array $roles = null): bool
    {
        $roles ??= $this->approvalRoles();
        $role = $roles[(int) $record->approval_stage] ?? null;
        $permission = match ($role) {
            'Department Head' => 'requests.department-approve',
            'Fund Approver' => 'fund.approve',
            default => null,
        };
        if (! $role || ! $user->is_active || (int) $record->created_by === $user->id
            || ! in_array($record->status, ['submitted', 'pending_review'], true)
            || ! $user->hasPermissionTo('requests.approve')
            || ($permission ? ! $user->hasPermissionTo($permission) : ! $user->hasRole($role))
            || ($role === 'Department Head' && (int) $user->department_id !== (int) $record->getAttribute('department_id'))) {
            return false;
        }

        // Returned revisions start a new cycle; immutable history must not bar re-review.
        $history ??= ApprovalDecision::where('subject_type', ItemRequest::class)->where('subject_id', $record->getKey())->get(['id', 'user_id', 'decision']);
        $cycleStart = $history->whereIn('decision', ['returned', 'rejected'])->max('id') ?? 0;

        return ! $history->contains(fn ($decision) => (int) $decision->id > (int) $cycleStart && (int) $decision->user_id === $user->id);
    }

    /** @param array<string, mixed> $data */
    private function requestDecision(User $user, array $data): ItemRequest
    {
        $record = $this->record($user, ItemRequest::class, (int) $data['id'], 'requests.approve', true);
        $this->ensure(in_array($record->status, ['submitted', 'pending_review'], true), 'This request is not awaiting approval.');
        $roles = $this->approvalRoles();
        $stage = (int) $record->approval_stage;
        $this->ensure($this->canReviewRequestStage($user, $record), 'An authorized, different approver is required at each stage.');
        $this->ensure(! ($record->is_unplanned && $data['decision'] === 'approved') || filled($data['comment'] ?? null), 'Explicit justification is required to approve unplanned items.', 'comment');
        $this->decision($user, $record, $data['decision'], $data['comment'] ?? null, $stage + 1);
        $before = $record->toArray();
        if ($data['decision'] === 'approved') {
            if ($stage + 1 === count($roles)) {
                $quantities = collect($this->commandLines($data))->keyBy('line_id');
                foreach ($record->lines()->lockForUpdate()->get() as $line) {
                    $qty = $quantities->get($line->id)['approved_quantity'] ?? $line->quantity;
                    $this->ensure(Decimal::cmp($qty, '0') > 0 && Decimal::cmp($qty, $line->quantity) <= 0, 'Approved quantity must be positive and no greater than requested.');
                    $line->update(['approved_quantity' => $qty]);
                }
                $record->update(['status' => 'approved', 'approval_stage' => $stage + 1, 'approved_at' => now()]);
            } else {
                $record->update(['status' => 'pending_review', 'approval_stage' => $stage + 1]);
            }
        } else {
            $record->update(['status' => $data['decision'], 'approval_stage' => 0]);
        }
        $this->audit($user, $record, 'request.'.$data['decision'], $before, $data['comment'] ?? null);

        return $record;
    }

    /** @param array<string, mixed> $data */
    private function cancelRequest(User $user, array $data): ItemRequest
    {
        $record = $this->record($user, ItemRequest::class, (int) $data['id'], 'requests.create');
        $this->requestOwner($user, $record);
        $this->ensure(! in_array($record->status, ['fulfilled', 'canceled', 'closed', 'rejected'], true), 'This request cannot be canceled.');
        $this->ensure(! $record->purchaseOrders()->whereNot('status', 'canceled')->exists() && ! $record->issuances()->exists(), 'Cancel purchase orders first; requests with issuances must be completed or returned.');
        $this->ensure(! $record->supplyReceipts()->exists(), 'Requests with received Supply items cannot be canceled.');
        $before = $record->toArray();
        $reservations = StockReservation::whereIn('item_request_line_id', $record->lines()->pluck('id'))->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
        $before['released_reservation_ids'] = $reservations->modelKeys();
        foreach ($reservations as $reservation) {
            $prior = $reservation->toArray();
            $reservation->update(['status' => 'released']);
            $this->audit($user, $reservation, 'stock.reservation_released', $prior, $data['reason'], (int) $record->getAttribute('funding_source_id'));
        }
        $record->update(['status' => 'canceled']);
        $this->audit($user, $record, 'request.canceled', $before, $data['reason']);

        return $record;
    }

    private function requestOwner(User $user, ItemRequest $record): void
    {
        if ((int) $record->created_by !== $user->id && ! $user->can('users.manage')) {
            throw new AuthorizationException('Only the requester or an authorized administrator may change this request.');
        }
    }

    /** @param array<string, mixed> $data */
    private function purchase(User $user, array $data): PurchaseOrder
    {
        $request = $this->record($user, ItemRequest::class, (int) $data['item_request_id'], 'procurement.manage');
        if (OfficeWorkflow::enabled()) {
            $this->ensure($request->channel === 'cash', 'Outside purchases must use a separate cash request.');
            $this->ensure(filled($data['authorization_reference'] ?? null), 'Record the external cash authorization before recording a purchase.', 'authorization_reference');
        }
        $this->ensure(in_array($request->status, ['approved', 'partially_fulfilled'], true), 'Only approved requests may be procured.');
        $lines = [];
        $seen = [];
        foreach ($data['lines'] as $input) {
            $line = $request->lines()->whereKey($input['line_id'])->lockForUpdate()->firstOrFail();
            $this->ensure(! in_array($line->id, $seen, true), 'Duplicate purchase line.');
            $seen[] = $line->id;
            $ordered = Decimal::sum(DB::table('purchase_order_lines')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')->where('item_request_line_id', $line->id)->where('purchase_orders.status', '<>', 'canceled')->pluck('quantity'), 4);
            $this->ensure(Decimal::cmp($input['quantity'], '0') > 0 && Decimal::cmp(Decimal::add($ordered, $input['quantity']), $line->approved_quantity) <= 0, 'Purchase quantities exceed the approved request.');
            $lines[] = ['item_request_line_id' => $line->id, 'item_id' => $line->item_id, 'quantity' => $input['quantity'], 'unit_price' => $input['unit_price'], 'total' => Decimal::mul($input['quantity'], $input['unit_price'])];
        }
        $this->ensure(count($lines) > 0, 'At least one purchase item is required.', 'lines');
        $order = PurchaseOrder::create(['reference' => 'PO-'.Str::upper(Str::random(10)), 'item_request_id' => $request->id, 'supplier_id' => $data['supplier_id'], 'funding_source_id' => $request->funding_source_id, 'fiscal_year_id' => $request->fiscal_year_id, 'department_id' => $request->department_id, 'created_by' => $user->id, 'status' => 'draft', 'total' => Decimal::sum(array_column($lines, 'total')), 'authorization_reference' => $data['authorization_reference'] ?? null, 'remarks' => $data['remarks'] ?? null]);
        $order->lines()->createMany($lines);
        if (OfficeWorkflow::enabled()) {
            $this->commitPurchase($user, $order, $data['remarks'] ?? null);
        }
        $this->audit($user, $order, 'purchase.created');

        return $order;
    }

    private function allocationFor(Model $record): BudgetAllocation
    {
        $allocation = BudgetAllocation::where('funding_source_id', $record->getAttribute('funding_source_id'))->where('fiscal_year_id', $record->getAttribute('fiscal_year_id'))->lockForUpdate()->first();
        $this->ensure($allocation !== null, 'Authorize a budget allocation for this fund and year first.');

        return $allocation;
    }

    /** @param array<string, mixed> $data */
    private function approvePurchase(User $user, array $data): PurchaseOrder
    {
        $order = $this->record($user, PurchaseOrder::class, (int) $data['id'], 'fund.approve', true);
        $this->ensure($order->status === 'draft', 'Only draft orders can be approved.');
        $this->decision($user, $order, 'approved', $data['comment'] ?? null);

        return $this->commitPurchase($user, $order, $data['comment'] ?? null);
    }

    private function commitPurchase(User $user, PurchaseOrder $order, ?string $comment): PurchaseOrder
    {
        $allocation = $this->allocationFor($order);
        $budget = $this->budget($allocation);
        if (Decimal::cmp($order->total, $budget['available'], 2) > 0) {
            $this->ensure($order->fund->budget_control === 'soft' && filled($comment), 'This order exceeds the remaining authorized budget. A hard limit cannot be overridden.');
        }
        FinancialEntry::create(['budget_allocation_id' => $allocation->id, 'purchase_order_id' => $order->id, 'user_id' => $user->id, 'kind' => 'commitment', 'commitment_delta' => $order->total, 'expenditure_delta' => '0', 'reference' => $order->reference, 'created_at' => now()]);
        $before = $order->toArray();
        $order->update(['status' => 'ordered', 'committed_amount' => $order->total]);
        $this->audit($user, $order, OfficeWorkflow::enabled() ? 'purchase.authority_recorded' : 'purchase.approved', $before, $comment);

        return $order;
    }

    /** @param array<string, mixed> $data */
    private function cancelPurchase(User $user, array $data): PurchaseOrder
    {
        $order = $this->record($user, PurchaseOrder::class, (int) $data['id'], 'procurement.manage');
        $this->ensure(in_array($order->status, ['draft', 'ordered', 'partially_received'], true), 'This order cannot be canceled.');
        $this->ensure(! $order->receipts()->exists(), 'Received orders must be closed after all expenditure is recorded; cancellation cannot erase received stock.');
        if (Decimal::cmp($order->committed_amount, '0', 2) > 0) {
            $allocation = $this->allocationFor($order);
            FinancialEntry::create(['budget_allocation_id' => $allocation->id, 'purchase_order_id' => $order->id, 'user_id' => $user->id, 'kind' => 'commitment_release', 'commitment_delta' => Decimal::sub('0', $order->committed_amount, 2), 'expenditure_delta' => '0', 'reference' => $data['reason'], 'created_at' => now()]);
        }
        $before = $order->toArray();
        $order->update(['status' => 'canceled', 'committed_amount' => '0']);
        $this->audit($user, $order, 'purchase.canceled', $before, $data['reason']);

        return $order;
    }

    /** @param array<string, mixed> $data */
    private function spend(User $user, array $data): PurchaseOrder
    {
        $order = $this->record($user, PurchaseOrder::class, (int) $data['id'], 'budgets.manage');
        $this->ensure(in_array($order->status, ['ordered', 'partially_received', 'received'], true), 'Only approved open orders may incur expenditure.');
        $allocation = $this->allocationFor($order);
        $amount = (string) $data['amount'];
        $this->ensure(Decimal::cmp($amount, '0', 2) > 0 && Decimal::cmp($amount, $order->committed_amount, 2) <= 0, 'Expenditure must be positive and cannot exceed the remaining approved commitment. Amend the order for higher costs.');
        $release = ! empty($data['final_payment']) ? $order->committed_amount : $amount;
        $this->ensure(empty($data['final_payment']) || $order->status === 'received', 'Final settlement requires completed receiving.');
        FinancialEntry::create(['budget_allocation_id' => $allocation->id, 'purchase_order_id' => $order->id, 'user_id' => $user->id, 'kind' => 'expenditure', 'commitment_delta' => Decimal::sub('0', $release, 2), 'expenditure_delta' => $amount, 'reference' => $data['document_reference'], 'created_at' => now()]);
        $before = $order->toArray();
        $order->update(['committed_amount' => Decimal::sub($order->committed_amount, $release, 2), 'spent_amount' => Decimal::add($order->spent_amount, $amount, 2), 'status' => ! empty($data['final_payment']) ? 'closed' : $order->status]);
        $this->audit($user, $order, 'expenditure.recorded', $before, $data['document_reference']);

        return $order;
    }

    private function balance(int $fund, int $warehouse, int $item): StockBalance
    {
        FundingSource::whereKey($fund)->lockForUpdate()->firstOrFail();
        DB::table('stock_balances')->insertOrIgnore(['funding_source_id' => $fund, 'warehouse_id' => $warehouse, 'item_id' => $item, 'quantity' => '0', 'created_at' => now(), 'updated_at' => now()]);

        return StockBalance::where('funding_source_id', $fund)->where('warehouse_id', $warehouse)->where('item_id', $item)->lockForUpdate()->firstOrFail();
    }

    private function move(User $user, StockBalance $balance, string $delta, string $kind, Model $source, string $remarks): StockMovement
    {
        $after = Decimal::add($balance->quantity, $delta);
        $this->ensure(Decimal::cmp($after, '0') >= 0, 'Insufficient stock. This transaction would make inventory negative.');
        $balance->update(['quantity' => $after]);

        return StockMovement::create(['stock_balance_id' => $balance->id, 'user_id' => $user->id, 'kind' => $kind, 'quantity_delta' => $delta, 'balance_after' => $after, 'source_type' => $source::class, 'source_id' => $source->getKey(), 'remarks' => $remarks, 'created_at' => now()]);
    }

    /** @param array<string, mixed> $data */
    private function receiveSupply(User $user, array $data): SupplyReceipt
    {
        $this->ensure(OfficeWorkflow::enabled(), 'Supply receiving is available in the office workflow.');
        $request = $this->record($user, ItemRequest::class, (int) $data['id'], 'inventory.manage');
        $this->ensure($request->channel === 'supply' && in_array($request->status, ['submitted', 'partially_received'], true), 'Only submitted Supply requests with outstanding items can be received.');
        Warehouse::whereKey($data['warehouse_id'])->where('is_active', true)->firstOrFail();
        $inputs = $this->commandLines($data);
        $this->ensure(count($inputs) > 0, 'At least one received item is required.', 'lines');
        $receipt = SupplyReceipt::create(['reference' => 'SUP-'.Str::upper(Str::random(10)), 'item_request_id' => $request->id, 'funding_source_id' => $request->funding_source_id, 'warehouse_id' => $data['warehouse_id'], 'received_by' => $user->id, 'delivery_reference' => $data['delivery_reference'], 'created_at' => now()]);
        $seen = [];
        foreach (collect($inputs)->sortBy('line_id') as $input) {
            $line = $request->lines()->whereKey($input['line_id'])->lockForUpdate()->firstOrFail();
            $this->ensure(! in_array($line->id, $seen, true), 'Duplicate Supply receiving line.', 'lines');
            $seen[] = $line->id;
            $quantity = (string) $input['quantity'];
            $this->ensure(Decimal::cmp($quantity, '0') > 0 && Decimal::cmp(Decimal::add($line->received_quantity, $quantity), $line->quantity) <= 0, 'Supply delivery exceeds the outstanding requested quantity.', 'lines');
            $receipt->lines()->create(['item_request_line_id' => $line->id, 'item_id' => $line->item_id, 'quantity' => $quantity]);
            $balance = $this->balance((int) $request->funding_source_id, (int) $data['warehouse_id'], (int) $line->item_id);
            $this->move($user, $balance, $quantity, 'supply_receipt', $receipt, $data['delivery_reference']);
            $line->update(['received_quantity' => Decimal::add($line->received_quantity, $quantity)]);
        }
        $complete = $request->lines()->get()->every(fn ($line) => Decimal::cmp($line->quantity, $line->received_quantity) === 0);
        $request->update(['status' => $complete ? 'fulfilled' : 'partially_received']);
        $this->audit($user, $receipt, 'supply.received');

        return $receipt;
    }

    /** @param array<string, mixed> $data */
    private function consume(User $user, array $data): StockMovement
    {
        $this->ensure(OfficeWorkflow::enabled(), 'Office consumption is available in the office workflow.');
        $balance = $this->record($user, StockBalance::class, (int) $data['id'], 'inventory.manage');
        $year = FiscalYear::whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->first();
        $this->ensure($year !== null, 'Create an open fiscal year covering today before recording office use.');
        $this->activeYear((int) $year->id);
        $quantity = (string) $data['quantity'];
        $this->ensure(Decimal::cmp($quantity, '0') > 0 && Decimal::cmp($quantity, $balance->quantity) <= 0, 'Office use must be positive and cannot exceed the stock on hand.', 'quantity');
        $this->ensure(filled($data['recipient'] ?? null) && filled($data['reason'] ?? null), 'Record the recipient and reason for office use.');
        $movement = $this->move($user, $balance, Decimal::sub('0', $quantity), 'consumption', $balance, 'Used by '.$data['recipient'].': '.$data['reason']);
        $this->audit($user, $movement, 'stock.consumed', [], $data['reason'], (int) $balance->funding_source_id);

        return $movement;
    }

    /** @param array<string, mixed> $data */
    private function receive(User $user, array $data): GoodsReceipt
    {
        $order = $this->record($user, PurchaseOrder::class, (int) $data['id'], 'inventory.manage');
        $this->ensure(in_array($order->status, ['ordered', 'partially_received'], true), 'Only approved orders with outstanding deliveries can be received.');
        $request = OfficeWorkflow::enabled() ? ItemRequest::whereKey($order->item_request_id)->lockForUpdate()->firstOrFail() : null;
        $receipt = GoodsReceipt::create(['reference' => 'GRN-'.Str::upper(Str::random(10)), 'purchase_order_id' => $order->id, 'funding_source_id' => $order->funding_source_id, 'warehouse_id' => $data['warehouse_id'], 'received_by' => $user->id, 'delivery_reference' => $data['delivery_reference'], 'created_at' => now()]);
        $seen = [];
        foreach (collect($this->commandLines($data))->sortBy('line_id') as $input) {
            $line = $order->lines()->whereKey($input['line_id'])->lockForUpdate()->firstOrFail();
            $this->ensure(! in_array($line->id, $seen, true), 'Duplicate receiving line.');
            $seen[] = $line->id;
            $quantity = (string) $input['quantity'];
            $this->ensure(Decimal::cmp($quantity, '0') > 0 && Decimal::cmp(Decimal::add($line->received_quantity, $quantity), $line->quantity) <= 0, 'Delivery exceeds the outstanding purchase quantity.');
            $receipt->lines()->create(['purchase_order_line_id' => $line->id, 'item_id' => $line->item_id, 'quantity' => $quantity, 'unit_cost' => $line->unit_price]);
            $balance = $this->balance((int) $order->funding_source_id, (int) $data['warehouse_id'], (int) $line->item_id);
            $this->move($user, $balance, $quantity, 'receipt', $receipt, $data['delivery_reference']);
            $line->update(['received_quantity' => Decimal::add($line->received_quantity, $quantity)]);
            if ($request?->channel === 'cash') {
                $requestLine = $request->lines()->whereKey($line->item_request_line_id)->lockForUpdate()->firstOrFail();
                $received = Decimal::add($requestLine->received_quantity, $quantity);
                $this->ensure(Decimal::cmp($received, $requestLine->quantity) <= 0, 'Outside deliveries exceed the cash request quantity.');
                $requestLine->update(['received_quantity' => $received]);
            }
        }
        $complete = $order->lines()->get()->every(fn ($line) => Decimal::cmp($line->quantity, $line->received_quantity) === 0);
        $order->update(['status' => $complete ? 'received' : 'partially_received']);
        if ($request?->channel === 'cash') {
            $cashComplete = $request->lines()->get()->every(fn ($line) => Decimal::cmp($line->quantity, $line->received_quantity) === 0);
            $request->update(['status' => $cashComplete ? 'fulfilled' : 'partially_fulfilled']);
        }
        $this->audit($user, $receipt, 'goods.received');

        return $receipt;
    }

    private function available(StockBalance $balance, ?int $requestLine = null): string
    {
        $query = $balance->reservations()->where('status', 'active');
        if ($requestLine !== null) {
            $query->where('item_request_line_id', '<>', $requestLine);
        }

        return Decimal::sub($balance->quantity, Decimal::sum($query->pluck('quantity'), 4));
    }

    /** @param array<string, mixed> $data */
    private function issue(User $user, array $data): StockIssuance
    {
        $request = $this->record($user, ItemRequest::class, (int) $data['id'], 'inventory.manage');
        $this->ensure(in_array($request->status, ['approved', 'partially_fulfilled'], true), 'Only approved outstanding requests may be issued.');
        $issuance = StockIssuance::create(['reference' => 'ISS-'.Str::upper(Str::random(10)), 'item_request_id' => $request->id, 'funding_source_id' => $request->funding_source_id, 'warehouse_id' => $data['warehouse_id'], 'issued_by' => $user->id, 'recipient' => $data['recipient'], 'created_at' => now()]);
        $seen = [];
        foreach (collect($this->commandLines($data))->sortBy('line_id') as $input) {
            $line = $request->lines()->whereKey($input['line_id'])->lockForUpdate()->firstOrFail();
            $this->ensure(! in_array($line->id, $seen, true), 'Duplicate issuance line.');
            $seen[] = $line->id;
            $quantity = (string) $input['quantity'];
            $this->ensure(Decimal::cmp($quantity, '0') > 0 && Decimal::cmp(Decimal::add($line->fulfilled_quantity, $quantity), $line->approved_quantity) <= 0, 'Issuance exceeds the unfulfilled approved quantity.');
            $balance = $this->balance((int) $request->funding_source_id, (int) $data['warehouse_id'], (int) $line->item_id);
            $this->ensure(Decimal::cmp($quantity, $this->available($balance, (int) $line->id)) <= 0, 'Insufficient available stock; other requests have reserved stock.');
            $issuance->lines()->create(['item_request_line_id' => $line->id, 'item_id' => $line->item_id, 'quantity' => $quantity]);
            $this->move($user, $balance, Decimal::sub('0', $quantity), 'issuance', $issuance, 'Released to '.$data['recipient']);
            $line->update(['fulfilled_quantity' => Decimal::add($line->fulfilled_quantity, $quantity)]);
            $reservation = StockReservation::where('stock_balance_id', $balance->id)->where('item_request_line_id', $line->id)->where('status', 'active')->lockForUpdate()->first();
            if ($reservation) {
                $remaining = Decimal::sub($reservation->quantity, $quantity);
                $reservation->update(['quantity' => Decimal::cmp($remaining, '0') > 0 ? $remaining : '0', 'status' => Decimal::cmp($remaining, '0') > 0 ? 'active' : 'fulfilled']);
            }
        }
        $this->fulfillmentStatus($user, $request);
        $this->audit($user, $issuance, 'stock.issued');

        return $issuance;
    }

    private function fulfillmentStatus(User $user, ItemRequest $request): void
    {
        $lines = $request->lines()->get();
        foreach ($lines as $line) {
            $remaining = Decimal::sub($line->approved_quantity, $line->fulfilled_quantity);
            $reservations = StockReservation::where('item_request_line_id', $line->id)->where('status', 'active')->orderBy('stock_balance_id')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $keep = Decimal::cmp($remaining, $reservation->quantity) >= 0 ? $reservation->quantity : $remaining;
                if (Decimal::cmp($keep, $reservation->quantity) < 0) {
                    $before = $reservation->toArray();
                    $reservation->update(['quantity' => $keep, 'status' => Decimal::cmp($keep, '0') > 0 ? 'active' : 'released']);
                    $this->audit($user, $reservation, 'stock.reservation_released', $before, 'Released excess reservation after fulfillment.', (int) $request->funding_source_id);
                }
                $remaining = Decimal::sub($remaining, $keep);
            }
        }
        $complete = $lines->every(fn ($line) => Decimal::cmp($line->fulfilled_quantity, $line->approved_quantity) === 0);
        $any = $lines->contains(fn ($line) => Decimal::cmp($line->fulfilled_quantity, '0') > 0);
        $request->update(['status' => $complete ? 'fulfilled' : ($any ? 'partially_fulfilled' : 'approved')]);
    }

    /** @param array<string, mixed> $data */
    private function returnStock(User $user, array $data): StockIssuance
    {
        $issuance = $this->record($user, StockIssuance::class, (int) $data['id'], 'inventory.manage');
        $request = $this->record($user, ItemRequest::class, (int) $issuance->item_request_id, 'inventory.manage');
        foreach (collect($this->commandLines($data))->sortBy('line_id') as $input) {
            $line = $issuance->lines()->whereKey($input['line_id'])->lockForUpdate()->firstOrFail();
            $qty = (string) $input['quantity'];
            $this->ensure(Decimal::cmp($qty, '0') > 0 && Decimal::cmp(Decimal::add($line->returned_quantity, $qty), $line->quantity) <= 0, 'Return exceeds the issued quantity.');
            $balance = $this->balance((int) $issuance->funding_source_id, (int) $issuance->warehouse_id, (int) $line->item_id);
            $this->move($user, $balance, $qty, 'return', $issuance, $data['reason']);
            $line->update(['returned_quantity' => Decimal::add($line->returned_quantity, $qty)]);
            $requestLine = ItemRequestLine::whereKey($line->item_request_line_id)->lockForUpdate()->firstOrFail();
            $requestLine->update(['fulfilled_quantity' => Decimal::sub($requestLine->fulfilled_quantity, $qty)]);
        }
        $this->fulfillmentStatus($user, $request);
        $this->audit($user, $issuance, 'stock.returned', [], $data['reason']);

        return $issuance;
    }

    /** @param array<string, mixed> $data */
    private function reserve(User $user, array $data): StockReservation
    {
        $request = $this->record($user, ItemRequest::class, (int) $data['id'], 'inventory.manage');
        $this->ensure(in_array($request->status, ['approved', 'partially_fulfilled'], true), 'Only approved outstanding requests may reserve stock.');
        $line = $request->lines()->whereKey($data['line_id'])->lockForUpdate()->firstOrFail();
        $qty = (string) $data['quantity'];
        $balance = $this->balance((int) $request->funding_source_id, (int) $data['warehouse_id'], (int) $line->item_id);
        $other = Decimal::sum(StockReservation::where('item_request_line_id', $line->id)->where('status', 'active')->where('stock_balance_id', '<>', $balance->id)->pluck('quantity'), 4);
        $remaining = Decimal::sub(Decimal::sub($line->approved_quantity, $line->fulfilled_quantity), $other);
        $this->ensure(Decimal::cmp($qty, '0') > 0 && Decimal::cmp($qty, $this->available($balance, (int) $line->id)) <= 0 && Decimal::cmp($qty, $remaining) <= 0, 'Reservation exceeds available stock or the remaining request.');
        $reservation = StockReservation::updateOrCreate(['item_request_line_id' => $line->id, 'stock_balance_id' => $balance->id], ['user_id' => $user->id, 'quantity' => $qty, 'status' => 'active']);
        $this->audit($user, $reservation, 'stock.reserved', [], null, (int) $request->funding_source_id);

        return $reservation;
    }

    /** @param array<string, mixed> $data */
    private function transfer(User $user, array $data): StockTransfer
    {
        Access::assert($user, 'inventory.manage', (int) $data['funding_source_id']);
        $effectiveOn = $data['effective_on'] ?? today()->format('Y-m-d');
        $year = isset($data['fiscal_year_id']) ? FiscalYear::whereKey($data['fiscal_year_id'])->firstOrFail() : FiscalYear::whereDate('starts_on', '<=', $effectiveOn)->whereDate('ends_on', '>=', $effectiveOn)->first();
        $this->ensure($year !== null, 'Open a fiscal year covering the transfer date first.');
        $year = $this->activeYear((int) $year->id);
        $this->ensure($effectiveOn >= (string) $year->starts_on && $effectiveOn <= (string) $year->ends_on, 'The transfer date must fall within the selected fiscal year.', 'effective_on');
        $this->ensure((int) $data['warehouse_id'] !== (int) $data['destination_warehouse_id'], 'Select two different storage locations.');
        $warehouses = [(int) $data['warehouse_id'], (int) $data['destination_warehouse_id']];
        sort($warehouses);
        $balances = [];
        foreach ($warehouses as $warehouse) {
            $balances[$warehouse] = $this->balance((int) $data['funding_source_id'], $warehouse, (int) $data['item_id']);
        }
        $source = $balances[$data['warehouse_id']];
        $destination = $balances[$data['destination_warehouse_id']];
        $qty = (string) $data['quantity'];
        $this->ensure(Decimal::cmp($qty, '0') > 0 && Decimal::cmp($qty, $this->available($source)) <= 0, 'Insufficient unreserved stock for transfer.');
        $transfer = StockTransfer::create(['reference' => 'TRF-'.Str::upper(Str::random(10)), 'funding_source_id' => $data['funding_source_id'], 'fiscal_year_id' => $year->id, 'effective_on' => $effectiveOn, 'source_warehouse_id' => $data['warehouse_id'], 'destination_warehouse_id' => $data['destination_warehouse_id'], 'item_id' => $data['item_id'], 'quantity' => $qty, 'created_by' => $user->id, 'reason' => $data['reason'], 'created_at' => now()]);
        $this->move($user, $source, Decimal::sub('0', $qty), 'transfer_out', $transfer, $data['reason']);
        $this->move($user, $destination, $qty, 'transfer_in', $transfer, $data['reason']);
        $this->audit($user, $transfer, 'stock.transferred', [], $data['reason']);

        return $transfer;
    }

    /** @param array<string, mixed> $data */
    private function count(User $user, array $data): InventoryCount
    {
        Access::assert($user, 'inventory.manage', (int) $data['funding_source_id']);
        $this->activeYear((int) $data['fiscal_year_id']);
        FundingSource::whereKey($data['funding_source_id'])->lockForUpdate()->firstOrFail();
        $counter = User::whereKey($data['counter_id'])->where('is_active', true)->firstOrFail();
        Access::assert($counter, 'inventory.manage', (int) $data['funding_source_id']);
        $this->ensure(! InventoryCount::where('funding_source_id', $data['funding_source_id'])->where('warehouse_id', $data['warehouse_id'])->whereNotIn('status', ['reconciled', 'closed', 'rejected'])->exists(), 'Finish the active count for this fund and location first.');
        // Every movement locks its balance; lock all current balances before recording cutoff and snapshot.
        $balances = StockBalance::where('funding_source_id', $data['funding_source_id'])->where('warehouse_id', $data['warehouse_id'])->orderBy('item_id')->lockForUpdate()->get()->keyBy('item_id');
        $count = InventoryCount::create(['reference' => 'CNT-'.Str::upper(Str::random(10)), 'funding_source_id' => $data['funding_source_id'], 'fiscal_year_id' => $data['fiscal_year_id'], 'warehouse_id' => $data['warehouse_id'], 'created_by' => $user->id, 'counter_id' => $counter->id, 'count_month' => $data['count_month'], 'cutoff_at' => now(), 'status' => 'counting']);
        Item::where('is_active', true)->orderBy('id')->chunkById(500, function ($items) use ($count, $balances) {
            DB::table('inventory_count_lines')->insert($items->map(fn ($item) => ['inventory_count_id' => $count->id, 'item_id' => $item->id, 'system_quantity' => $balances->has($item->id) ? $balances->get($item->id)->quantity : '0'])->all());
        });
        $this->audit($user, $count, 'count.created');

        return $count;
    }

    /** @param array<string, mixed> $data */
    private function encodeCount(User $user, array $data): InventoryCount
    {
        $count = $this->record($user, InventoryCount::class, (int) $data['id'], 'inventory.manage');
        $this->ensure((int) $count->counter_id === $user->id, 'Only the assigned counter may encode this count.');
        $this->ensure(in_array($count->status, ['counting', 'returned'], true), 'This count cannot be edited after submission.');
        foreach ($data['lines'] as $input) {
            $line = $count->lines()->whereKey($input['line_id'])->lockForUpdate()->firstOrFail();
            $physical = (string) $input['physical_quantity'];
            $variance = Decimal::sub($physical, $line->system_quantity);
            $this->ensure(Decimal::cmp($physical, '0') >= 0 && (Decimal::cmp($variance, '0') === 0 || filled($input['variance_reason'] ?? null)), 'Every discrepancy requires a variance reason.');
            $line->update(['physical_quantity' => $physical, 'variance' => $variance, 'variance_reason' => $input['variance_reason'] ?? null, 'counted_by' => $user->id, 'counted_at' => now()]);
        }
        $count->update(['status' => 'counting']);
        $this->audit($user, $count, 'count.encoded');

        return $count;
    }

    /** @param array<string, mixed> $data */
    private function submitCount(User $user, array $data): InventoryCount
    {
        $count = $this->record($user, InventoryCount::class, (int) $data['id'], 'inventory.manage');
        $this->ensure((int) $count->counter_id === $user->id && $count->status === 'counting', 'Only the assigned counter can submit an in-progress count.');
        $this->ensure(! $count->lines()->whereNull('physical_quantity')->exists() && $count->lines()->exists(), 'Encode all items before submission.');
        $count->update(['status' => OfficeWorkflow::enabled() ? 'approved' : 'submitted', 'approved_at' => OfficeWorkflow::enabled() ? now() : null]);
        $this->audit($user, $count, 'count.submitted');

        return $count;
    }

    /** @param array<string, mixed> $data */
    private function decideCount(User $user, array $data): InventoryCount
    {
        $count = $this->record($user, InventoryCount::class, (int) $data['id'], 'counts.approve', true);
        $this->ensure((int) $count->counter_id !== $user->id && $count->status === 'submitted', 'Counters cannot approve their own count; only submitted counts may be reviewed.');
        $this->decision($user, $count, $data['decision'], $data['comment'] ?? null);
        $count->update(['status' => $data['decision'], 'approved_at' => $data['decision'] === 'approved' ? now() : null]);
        $this->audit($user, $count, 'count.'.$data['decision'], [], $data['comment'] ?? null);

        return $count;
    }

    /** @param array<string, mixed> $data */
    private function reconcile(User $user, array $data): InventoryCount
    {
        $count = $this->record($user, InventoryCount::class, (int) $data['id'], 'inventory.manage');
        $this->ensure($count->status === 'approved', 'Only approved counts may produce reconciliation adjustments.');
        foreach ($count->lines()->orderBy('item_id')->lockForUpdate()->get() as $line) {
            $balance = $this->balance((int) $count->funding_source_id, (int) $count->warehouse_id, (int) $line->item_id);
            // Apply only the approved cutoff variance, preserving all receipts and issuances since cutoff.
            if (Decimal::cmp($line->variance, '0') !== 0) {
                $this->move($user, $balance, $line->variance, 'count_adjustment', $count, (string) $line->variance_reason);
            }
        }
        $count->update(['status' => 'reconciled', 'reconciled_at' => now()]);
        $this->audit($user, $count, 'count.reconciled');

        return $count;
    }
}
