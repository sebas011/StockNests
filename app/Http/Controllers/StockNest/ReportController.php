<?php

namespace App\Http\Controllers\StockNest;

use App\Domain\Access;
use App\Domain\Decimal;
use App\Domain\WorkflowService;
use App\Http\Controllers\Controller;
use App\Models\AnnualProjection;
use App\Models\AuditLog;
use App\Models\BudgetAllocation;
use App\Models\FinancialEntry;
use App\Models\InventoryCount;
use App\Models\ItemRequest;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockIssuance;
use App\Models\StockMovement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

final class ReportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, WorkflowService $workflow)
    {
        Access::assert($request->user(), 'reports.view');
        if ($report === 'audit') {
            Access::assert($request->user(), 'audit.view');
        }
        $request->validate(['fund_id' => ['nullable', 'integer'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'fiscal_year_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'warehouse_id' => ['nullable', 'integer'], 'item_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:50'], 'q' => ['nullable', 'string', 'max:200']]);
        if ($request->filled('fund_id')) {
            abort_unless(in_array($request->integer('fund_id'), Access::fundIds($request->user()), true), 403);
        }
        $query = function (string $class, bool $department = false) use ($request): Builder {
            $q = Access::scope($class::query(), $request->user(), $department);
            if ($request->filled('fund_id')) {
                $q->where('funding_source_id', $request->integer('fund_id'));
            }
            foreach (['date_from' => '>=', 'date_to' => '<='] as $input => $operator) {
                if ($request->filled($input)) {
                    $q->whereDate('created_at', $operator, $request->input($input));
                }
            }
            foreach (['fiscal_year_id', 'department_id', 'warehouse_id', 'item_id', 'supplier_id', 'status'] as $column) {
                if ($request->filled($column) && Schema::hasColumn($q->getModel()->getTable(), $column)) {
                    $q->where($column, $request->input($column));
                }
            }
            if ($request->filled('q') && Schema::hasColumn($q->getModel()->getTable(), 'reference')) {
                $q->where('reference', 'like', '%'.$request->input('q').'%');
            }

            return $q;
        };
        $rows = match ($report) {
            'annual-plans' => $query(AnnualProjection::class, true)->with('fund', 'fiscalYear', 'department', 'lines.item')->get()->flatMap(fn ($r) => $r->lines->map(fn ($l) => ['reference' => $r->reference, 'fund' => $r->fund->code, 'year' => $r->fiscalYear->year, 'department' => $r->department->name, 'original_projection_id' => $r->original_projection_id ?? $r->id, 'revision' => $r->revision, 'status' => $r->status, 'item' => $l->item->name, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'total' => $l->total]))->all(),
            'budgets' => $query(BudgetAllocation::class)->with('fund', 'fiscalYear')->get()->map(function ($a) use ($workflow, $query) {
                $plans = $query(AnnualProjection::class, true)->where('funding_source_id', $a->funding_source_id)->where('fiscal_year_id', $a->fiscal_year_id)->where('status', 'approved')->get();

                return ['fund' => $a->fund->code, 'year' => $a->fiscalYear->year, 'original_plan' => Decimal::sum($plans->whereNull('original_projection_id')->pluck('total')), 'revised_plan' => Decimal::sum($plans->groupBy(fn ($p) => $p->original_projection_id ?? $p->id)->map(fn ($versions) => $versions->sortByDesc('revision')->first()->total))] + $workflow->budget($a);
            })->all(),
            'requests' => $query(ItemRequest::class, true)->with('fund', 'department', 'creator', 'lines.item')->get()->flatMap(fn ($r) => $r->lines->map(fn ($l) => ['reference' => $r->reference, 'fund' => $r->fund->code, 'department' => $r->department->name, 'requester' => $r->creator->name, 'date' => $r->created_at->toIso8601String(), 'status' => $r->status, 'item' => $l->item->name, 'requested' => $l->quantity, 'approved' => $l->approved_quantity, 'fulfilled' => $l->fulfilled_quantity, 'unplanned' => $l->is_unplanned ? 'Yes' : 'No']))->all(),
            'purchases' => $query(PurchaseOrder::class, true)->with('fund', 'supplier', 'request', 'lines.item')->get()->flatMap(fn ($r) => $r->lines->map(fn ($l) => ['reference' => $r->reference, 'request' => $r->request->reference, 'fund' => $r->fund->code, 'supplier' => $r->supplier->name, 'status' => $r->status, 'item' => $l->item->name, 'ordered' => $l->quantity, 'received' => $l->received_quantity, 'unit_price' => $l->unit_price, 'line_total' => $l->total, 'order_commitment' => $r->committed_amount, 'order_expenditure' => $r->spent_amount]))->all(),
            'stock' => $query(StockBalance::class)->with('fund', 'warehouse', 'item')->get()->map(fn ($b) => ['fund' => $b->fund->code, 'location' => $b->warehouse->name, 'sku' => $b->item->sku, 'item' => $b->item->name, 'quantity' => $b->quantity, 'reserved' => Decimal::sum($b->reservations()->where('status', 'active')->pluck('quantity'), 4), 'reorder_point' => $b->item->reorder_point, 'low_stock' => Decimal::cmp($b->quantity, $b->item->reorder_point) <= 0 ? 'Yes' : 'No', 'estimated_value' => Decimal::mul($b->quantity, $b->item->standard_cost)])->all(),
            'movements' => $this->movementRows($request),
            'issuances' => $query(StockIssuance::class)->whereHas('request', fn ($q) => $this->department($q, $request))->with('request', 'lines.item', 'warehouse')->get()->flatMap(fn ($r) => $r->lines->map(fn ($l) => ['reference' => $r->reference, 'request' => $r->request->reference, 'fund_id' => $r->funding_source_id, 'location' => $r->warehouse->name, 'recipient' => $r->recipient, 'date' => $r->created_at->toIso8601String(), 'item' => $l->item->name, 'issued' => $l->quantity, 'returned' => $l->returned_quantity]))->all(),
            'counts' => $query(InventoryCount::class)->with('fund', 'warehouse', 'counter', 'lines.item')->get()->flatMap(fn ($r) => $r->lines->map(fn ($l) => ['reference' => $r->reference, 'fund' => $r->fund->code, 'location' => $r->warehouse->name, 'month' => $r->count_month, 'cutoff' => $r->cutoff_at->toIso8601String(), 'counter' => $r->counter->name, 'status' => $r->status, 'item' => $l->item->name, 'system_quantity' => $l->system_quantity, 'physical_quantity' => $l->physical_quantity, 'variance' => $l->variance, 'variance_reason' => $l->variance_reason]))->all(),
            'expenditures' => $this->financialRows($request),
            'audit' => $query(AuditLog::class)->with('user', 'fund')->get()->map(fn ($r) => ['timestamp' => $r->created_at->toIso8601String(), 'actor' => $r->user?->name, 'fund' => $r->fund?->code, 'action' => $r->action, 'record_type' => class_basename($r->subject_type), 'record_id' => $r->subject_id, 'reason' => $r->reason, 'before' => json_encode($r->before_values), 'after' => json_encode($r->after_values)])->all(),
            default => abort(404),
        };
        $headings = count($rows) ? array_keys($rows[0]) : ['No matching records'];
        $matrix = array_map(fn ($r) => array_values($r), $rows);
        // Spreadsheet formula injection is also possible through legitimate text fields.
        $matrix = array_map(fn ($row) => array_map(fn ($v) => is_string($v) && ! is_numeric($v) && preg_match('/^[=+\-@]/', preg_replace('/^[\x00-\x20]+/', '', $v)) ? "'".$v : $v, $row), $matrix);
        $filename = 'stocknest-'.$report.'-'.now()->format('Ymd-His').'.'.$format;
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headings, $matrix) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, $headings, ',', '"', '');
                foreach ($matrix as $row) {
                    fputcsv($handle, $row, ',', '"', '');
                } fclose($handle);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        if ($format === 'xlsx') {
            // Preserve exact decimal strings beyond Excel's 15-digit numeric limit.
            // Explicit text binding also prevents formulas in user-entered values.
            $export = new class(array_map(fn ($row) => array_values($row), $rows), $headings) extends StringValueBinder implements FromArray, WithCustomValueBinder, WithHeadings
            {
                public function __construct(private array $rows, private array $columns) {}

                public function array(): array
                {
                    return $this->rows;
                }

                public function headings(): array
                {
                    return $this->columns;
                }
            };

            return Excel::download($export, $filename);
        }
        abort_unless($format === 'pdf', 404);
        $escape = fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#172c2c}h1{font-size:18px}table{border-collapse:collapse;width:100%}th,td{padding:5px;border:1px solid #ccd6d6;text-align:left;overflow-wrap:anywhere}th{background:#e8f1ee}thead{display:table-header-group}</style></head><body><h1>StockNest · '.$escape(ucwords(str_replace('-', ' ', $report))).'</h1><p>Generated '.$escape(now()->timezone('Asia/Manila')->format('Y-m-d H:i T')).' · '.$escape($request->user()->name).' · PHP</p><p>Filters: '.$escape(http_build_query($request->query())).'</p><table><thead><tr>';
        foreach ($headings as $heading) {
            $html .= '<th>'.$escape(ucwords(str_replace('_', ' ', $heading))).'</th>';
        } $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>'.$escape($value).'</td>';
            } $html .= '</tr>';
        } $html .= '</tbody></table></body></html>';

        return Pdf::loadHTML($html)->setPaper('a3', 'landscape')->download($filename);
    }

    private function department(Builder $query, Request $request): void
    {
        if (Access::departmentLimited($request->user())) {
            $query->where('department_id', $request->user()->department_id);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
    }

    private function movementRows(Request $request): array
    {
        $query = StockMovement::whereHas('balance', function ($q) use ($request) {
            $q->whereIn('funding_source_id', Access::fundIds($request->user()));
            foreach (['fund_id' => 'funding_source_id', 'warehouse_id' => 'warehouse_id', 'item_id' => 'item_id'] as $input => $column) {
                if ($request->filled($input)) {
                    $q->where($column, $request->integer($input));
                }
            }
        })->with('balance.fund', 'balance.item', 'balance.warehouse', 'user');
        foreach (['date_from' => '>=', 'date_to' => '<='] as $input => $operator) {
            if ($request->filled($input)) {
                $query->whereDate('created_at', $operator, $request->input($input));
            }
        }

        return $query->get()->map(fn ($m) => ['timestamp' => $m->created_at->toIso8601String(), 'fund' => $m->balance->fund->code, 'location' => $m->balance->warehouse->name, 'item' => $m->balance->item->name, 'kind' => $m->kind, 'quantity_delta' => $m->quantity_delta, 'balance_after' => $m->balance_after, 'responsible_user' => $m->user->name, 'source' => class_basename($m->source_type).' #'.$m->source_id, 'remarks' => $m->remarks])->all();
    }

    private function financialRows(Request $request): array
    {
        $query = FinancialEntry::whereHas('allocation', function ($q) use ($request) {
            $q->whereIn('funding_source_id', Access::fundIds($request->user()));
            if ($request->filled('fund_id')) {
                $q->where('funding_source_id', $request->integer('fund_id'));
            } if ($request->filled('fiscal_year_id')) {
                $q->where('fiscal_year_id', $request->integer('fiscal_year_id'));
            }
        })->whereHas('order', fn ($q) => $this->department($q, $request))->with('allocation.fund', 'allocation.fiscalYear', 'order');
        foreach (['date_from' => '>=', 'date_to' => '<='] as $input => $operator) {
            if ($request->filled($input)) {
                $query->whereDate('created_at', $operator, $request->input($input));
            }
        }

        return $query->get()->map(fn ($e) => ['timestamp' => $e->created_at->toIso8601String(), 'fund' => $e->allocation->fund->code, 'year' => $e->allocation->fiscalYear->year, 'purchase_order' => $e->order->reference, 'kind' => $e->kind, 'commitment_change' => $e->commitment_delta, 'actual_expenditure' => $e->expenditure_delta, 'document_reference' => $e->reference])->all();
    }
}
