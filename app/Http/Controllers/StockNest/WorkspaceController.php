<?php

namespace App\Http\Controllers\StockNest;

use App\Domain\Access;
use App\Domain\Decimal;
use App\Domain\WorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockNest\WorkflowRequest;
use App\Models\AnnualProjection;
use App\Models\ApprovalDecision;
use App\Models\AuditLog;
use App\Models\BudgetAdjustment;
use App\Models\BudgetAllocation;
use App\Models\Department;
use App\Models\FinancialEntry;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\InventoryCount;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemRequest;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockIssuance;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceController extends Controller
{
    private User $actor;

    private array $options;

    private Request $request;

    public function __construct(private WorkflowService $workflow) {}

    public function dispatch(WorkflowRequest $request, string $operation)
    {
        $result = $this->workflow->execute($request->user(), $operation, $request->validated(), (string) $request->input('idempotency_key'));

        return back()->with('success', 'Transaction saved: '.$result['reference']);
    }

    public function index(Request $request, string $section = 'overview'): Response
    {
        $this->actor = $request->user();
        $this->request = $request;
        $permission = ['overview' => 'dashboard.view', 'funding' => 'budgets.manage', 'catalog' => 'dashboard.view', 'projections' => 'dashboard.view', 'requests' => 'dashboard.view', 'purchasing' => 'procurement.manage', 'inventory' => 'inventory.manage', 'counts' => 'inventory.manage', 'approvals' => 'requests.approve', 'reports' => 'reports.view', 'audit' => 'audit.view', 'administration' => 'users.manage'][$section] ?? null;
        abort_unless($permission !== null, 404);
        if ($section === 'purchasing' && $this->actor->hasAnyPermission(['inventory.manage', 'budgets.manage'])) {
            $permission = 'dashboard.view';
        }
        if ($section === 'approvals' && $this->actor->hasPermissionTo('counts.approve')) {
            $permission = 'counts.approve';
        }
        Access::assert($this->actor, $permission);
        $request->validate(['fund_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'string', 'max:50'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'sort' => ['nullable', 'string', 'max:60'], 'direction' => ['nullable', 'in:asc,desc']]);
        if ($request->filled('fund_id')) {
            abort_unless(in_array($request->integer('fund_id'), Access::fundIds($this->actor), true), 403);
        }
        $funds = $this->actor->fundingSources()->where('is_active', true)->get();
        $departments = Department::where('is_active', true);
        if (Access::departmentLimited($this->actor)) {
            $departments->whereKey($this->actor->department_id);
        }
        $this->options = [
            'fund' => $funds->map(fn ($f) => ['value' => (string) $f->id, 'label' => $f->code.' · '.$f->name])->all(),
            'year' => FiscalYear::where('status', 'open')->orderByDesc('year')->get()->map(fn ($y) => ['value' => (string) $y->id, 'label' => (string) $y->year])->all(),
            'department' => $departments->get()->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->name])->all(),
            'item' => Item::where('is_active', true)->orderBy('name')->get()->map(fn ($i) => ['value' => (string) $i->id, 'label' => $i->sku.' · '.$i->name])->all(),
            'category' => $this->lookup(ItemCategory::class), 'unit' => $this->lookup(UnitOfMeasure::class), 'supplier' => $this->lookup(Supplier::class), 'warehouse' => $this->lookup(Warehouse::class),
        ];
        $data = match ($section) {
            'overview' => $this->overview(), 'funding' => $this->funding(), 'catalog' => $this->catalog(), 'projections' => $this->projections(), 'requests' => $this->requests(), 'purchasing' => $this->purchasing(), 'inventory' => $this->inventory(), 'counts' => $this->counts(), 'approvals' => $this->approvals(), 'reports' => $this->reports(), 'audit' => $this->audit(), 'administration' => $this->administration(),
        };

        return Inertia::render('stocknest', $data + ['funds' => $funds->map(fn ($f) => ['id' => $f->id, 'label' => $f->code])->all(), 'selectedFund' => (string) $request->input('fund_id', ''), 'filters' => $request->only(['q', 'status', 'fund_id', 'date_from', 'date_to', 'sort', 'direction'])]);
    }

    private function lookup(string $class): array
    {
        return $class::where('is_active', true)->orderBy('name')->get()->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->name])->all();
    }

    private function can(string $permission): bool
    {
        return $this->actor->hasPermissionTo($permission);
    }

    private function query(string $class, bool $department = false): Builder
    {
        $query = Access::scope($class::query(), $this->actor, $department);
        if ($this->request->filled('fund_id')) {
            $query->where('funding_source_id', $this->request->integer('fund_id'));
        }
        if ($this->request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $this->request->input('date_from'));
        }
        if ($this->request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $this->request->input('date_to'));
        }

        return $query;
    }

    private function listing(Builder $query, array $search = ['reference'], bool $status = true): array
    {
        if ($this->request->filled('q')) {
            $term = '%'.$this->request->input('q').'%';
            $query->where(function ($q) use ($search, $term) {
                foreach ($search as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }
        if ($status && $this->request->filled('status')) {
            $query->where('status', $this->request->input('status'));
        }
        $sort = (string) $this->request->input('sort', 'id');
        $allowed = ['id', 'reference', 'status', 'created_at', 'name', 'sku', 'quantity', 'total', 'estimated_total'];
        $query->orderBy(in_array($sort, $allowed, true) && Schema::hasColumn($query->getModel()->getTable(), $sort) ? $sort : 'id', $this->request->input('direction', 'desc'));
        $result = $query->paginate(15)->withQueryString();

        return [$result->getCollection(), ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total(), 'prev_page_url' => $result->previousPageUrl(), 'next_page_url' => $result->nextPageUrl()]];
    }

    private function page(string $key, string $title, string $subtitle, array $columns, array $rows, ?array $pagination = null): array
    {
        $cols = [];
        foreach ($columns as $keyName => $definition) {
            [$label, $format] = is_array($definition) ? $definition : [$definition, 'text'];
            $cols[] = ['key' => $keyName, 'label' => $label, 'format' => $format];
        }

        return ['page' => ['key' => $key, 'title' => $title, 'subtitle' => $subtitle, 'columns' => $cols, 'rows' => $rows, 'pagination' => $pagination]];
    }

    private function field(string $name, string $label, string $type = 'text', bool $required = true, ?array $options = null, ?string $description = null): array
    {
        return array_filter(['name' => $name, 'label' => $label, 'type' => $type, 'required' => $required, 'options' => $options, 'step' => $type === 'number' ? (str_contains($name, 'price') || in_array($name, ['amount', 'standard_cost'], true) ? '0.01' : '0.0001') : null, 'description' => $description], fn ($v) => $v !== null);
    }

    private function select(string $name, string $label, string $options, bool $required = true): array
    {
        return $this->field($name, $label, 'select', $required, $this->options[$options]);
    }

    private function form(string $title, string $operation, array $fields = [], array $defaults = [], array $lineFields = []): array
    {
        $context = array_intersect_key($defaults, array_flip(['id', 'kind', 'decision', 'item_request_id', 'original_projection_id']));

        return ['title' => $title, 'action' => '/app/actions/'.$operation, 'method' => 'post', 'fields' => $fields, 'defaults' => $defaults, 'context' => $context, 'lineFields' => $lineFields, 'submitLabel' => $title];
    }

    private function action(string $title, array $form, string $tone = 'default'): array
    {
        return ['label' => $title, 'tone' => $tone, 'form' => $form];
    }

    private function totals(?int $year = null): array
    {
        $funds = [];
        $allocations = $this->query(BudgetAllocation::class)->when($year, fn ($q) => $q->whereHas('fiscalYear', fn ($fy) => $fy->where('year', $year)))->with('fund', 'fiscalYear')->get();
        foreach ($allocations as $allocation) {
            $projections = $this->query(AnnualProjection::class, true)->where('fiscal_year_id', $allocation->fiscal_year_id)->where('funding_source_id', $allocation->funding_source_id)->where('status', 'approved')->get();
            $original = Decimal::sum($projections->whereNull('original_projection_id')->pluck('total'));
            $revised = Decimal::sum($projections->groupBy(fn ($p) => $p->original_projection_id ?? $p->id)->map(fn ($versions) => $versions->sortByDesc('revision')->first()->total));
            $funds[] = ['id' => $allocation->id, 'name' => $allocation->fund->code.' · '.$allocation->fiscalYear->year, 'original' => $original, 'revised' => $revised] + $this->workflow->budget($allocation);
        }
        $plansWithoutAllocation = $this->query(AnnualProjection::class, true)->where('status', 'approved')
            ->when($year, fn ($q) => $q->whereHas('fiscalYear', fn ($fy) => $fy->where('year', $year)))
            ->with('fund', 'fiscalYear')->get()->groupBy(fn ($plan) => $plan->funding_source_id.'-'.$plan->fiscal_year_id);
        foreach ($plansWithoutAllocation as $versions) {
            $plan = $versions->first();
            if ($allocations->contains(fn ($allocation) => (int) $allocation->funding_source_id === (int) $plan->funding_source_id && (int) $allocation->fiscal_year_id === (int) $plan->fiscal_year_id)) {
                continue;
            }
            $funds[] = [
                'id' => 'planning-'.$plan->funding_source_id.'-'.$plan->fiscal_year_id,
                'name' => $plan->fund->code.' · '.$plan->fiscalYear->year,
                'original' => Decimal::sum($versions->whereNull('original_projection_id')->pluck('total')),
                'revised' => Decimal::sum($versions->groupBy(fn ($p) => $p->original_projection_id ?? $p->id)->map(fn ($group) => $group->sortByDesc('revision')->first()->total)),
                'allocation' => '0.00', 'committed' => '0.00', 'spent' => '0.00', 'available' => '0.00',
            ];
        }

        return $funds;
    }

    private function overview(): array
    {
        $funds = $this->totals((int) now()->year);
        $recent = $this->can('audit.view') ? $this->query(AuditLog::class)->with('user')->latest('id')->limit(8)->get() : collect();
        $requestQuery = $this->query(ItemRequest::class, true);
        $pending = (clone $requestQuery)->whereIn('status', ['submitted', 'pending_review'])->count();
        $balances = $this->query(StockBalance::class)->with('item')->get();
        $metrics = [
            ['label' => 'Authorized allocation', 'value' => Decimal::sum(array_column($funds, 'allocation')), 'format' => 'money', 'helper' => 'Approved spending authority'],
            ['label' => 'Committed', 'value' => Decimal::sum(array_column($funds, 'committed')), 'format' => 'money', 'helper' => 'Open purchase obligations'],
            ['label' => 'Actual expenditure', 'value' => Decimal::sum(array_column($funds, 'spent')), 'format' => 'money', 'helper' => 'Recorded payments'],
            ['label' => 'Available budget', 'value' => Decimal::sum(array_column($funds, 'available')), 'format' => 'money', 'helper' => 'Allocation less obligations and spending'],
            ['label' => 'Pending requests', 'value' => $pending, 'helper' => 'Awaiting review'],
            ['label' => 'Low stock lines', 'value' => $balances->filter(fn ($b) => Decimal::cmp($b->quantity, $b->item->reorder_point) <= 0)->count(), 'helper' => 'At or below reorder point'],
            ['label' => 'Inventory value estimate', 'value' => Decimal::sum($balances->map(fn ($b) => Decimal::mul($b->quantity, $b->item->standard_cost))), 'format' => 'money', 'helper' => 'Current quantity × catalog standard cost'],
            ['label' => 'Open purchase orders', 'value' => $this->query(PurchaseOrder::class, true)->whereIn('status', ['draft', 'ordered', 'partially_received', 'received'])->count(), 'helper' => 'Receiving or settlement outstanding'],
        ];
        $chart = [];
        $entries = FinancialEntry::whereHas('allocation', fn ($q) => $q->whereIn('funding_source_id', Access::fundIds($this->actor)))->when($this->request->filled('fund_id'), fn ($q) => $q->whereHas('allocation', fn ($a) => $a->where('funding_source_id', $this->request->integer('fund_id'))))->get();
        $latestPlans = $this->query(AnnualProjection::class, true)->where('status', 'approved')->whereHas('fiscalYear', fn ($q) => $q->where('year', now()->year))->with('lines')->get()
            ->groupBy(fn ($p) => $p->original_projection_id ?? $p->id)->map(fn ($versions) => $versions->sortByDesc('revision')->first());
        $scheduledLines = $latestPlans->flatMap(fn ($plan) => $plan->lines)->filter(fn ($line) => $line->planned_month !== null);
        for ($month = 1; $month <= 12; $month++) {
            $chart[] = ['month' => now()->startOfYear()->month($month)->format('M'), 'projected' => Decimal::sum($scheduledLines->filter(fn ($line) => (int) $line->planned_month === $month)->pluck('total')), 'actual' => Decimal::sum($entries->filter(fn ($e) => (int) $e->created_at?->format('n') === $month && (int) $e->created_at?->format('Y') === (int) now()->format('Y'))->pluck('expenditure_delta'))];
        }
        $chartYear = (int) now()->year;

        return $this->page('overview', 'Your inventory, in balance.', 'Live fund obligations, stock, and approvals in one workspace.', ['fund' => 'Funding source', 'original' => ['Original plan', 'money'], 'revised' => ['Revised plan', 'money'], 'allocation' => ['Allocation', 'money'], 'committed' => ['Committed', 'money'], 'spent' => ['Spent', 'money'], 'available' => ['Available', 'money']], array_map(fn ($f) => ['fund' => $f['name']] + $f, $funds)) + ['fiscalYear' => $chartYear, 'metrics' => $metrics, 'budgetFunds' => $funds, 'chartData' => $chart, 'activity' => $recent->map(fn ($a) => ['id' => $a->id, 'title' => str_replace('.', ' · ', $a->action), 'description' => ($a->user?->name ?? 'System').' · record '.$a->subject_id, 'date' => $a->created_at?->toIso8601String(), 'type' => Str::before($a->action, '.')])->all(), 'notices' => count($funds) ? [] : [['title' => 'Set up your annual allocations', 'description' => 'Budget officers submit allocation changes; a separate fund approver authorizes them.', 'tone' => 'info']]];
    }

    private function scopeFields(bool $department = true): array
    {
        $fields = [$this->select('funding_source_id', 'Funding source', 'fund'), $this->select('fiscal_year_id', 'Fiscal year', 'year')];
        if ($department) {
            $fields[] = $this->select('department_id', 'Department', 'department');
        }

        return $fields;
    }

    private function funding(): array
    {
        $funds = $this->totals();
        $adjustments = BudgetAdjustment::whereHas('allocation', fn ($q) => $q->whereIn('funding_source_id', Access::fundIds($this->actor)))->with('allocation.fund')->latest()->limit(30)->get();

        return $this->page('funding', 'Funding & budgets', 'GAA and APPCSE remain independently traceable. Allocations require separate approval.', ['fund' => 'Fund / fiscal year', 'original' => ['Original plan', 'money'], 'revised' => ['Revised plan', 'money'], 'allocation' => ['Authorized', 'money'], 'committed' => ['Committed', 'money'], 'spent' => ['Spent', 'money'], 'available' => ['Available', 'money']], array_map(fn ($f) => ['fund' => $f['name'], 'details' => ['allocation_changes' => $adjustments->where('budget_allocation_id', $f['id'])->map(fn ($a) => ['id' => $a->id, 'amount' => $a->amount, 'reason' => $a->reason, 'status' => $a->status])->values()->all()]] + $f, $funds)) + ['budgetFunds' => $funds, 'createForm' => $this->form('Submit allocation change', 'allocation.create', [...$this->scopeFields(false), $this->field('amount', 'Amount to add / reduce', 'number', true, null, 'Negative values reduce the allocation. Changes require fund approver approval.'), $this->field('reason', 'Authority / reason', 'textarea')])];
    }

    private function masterForm(string $kind, string $label): array
    {
        $fields = [$this->field('code', 'Code'), $this->field('name', 'Name')];
        $defaults = ['kind' => $kind, 'is_active' => true];
        if ($kind === 'fund') {
            $fields[] = $this->field('accounting_reference', 'Accounting reference', 'text', false);
            $fields[] = $this->field('budget_control', 'Budget control', 'select', true, [['value' => 'hard', 'label' => 'Hard limit'], ['value' => 'soft', 'label' => 'Soft warning · approval comment required']]);
            $defaults['budget_control'] = 'hard';
        }
        if ($kind === 'supplier') {
            $fields[] = $this->field('email', 'Email', 'email', false);
            $fields[] = $this->field('address', 'Address', 'textarea', false);
        }
        if ($kind === 'fiscal-year') {
            $fields = [$this->field('year', 'Year', 'number'), $this->field('starts_on', 'Start date', 'date'), $this->field('ends_on', 'End date', 'date'), $this->field('status', 'Status', 'select', true, [['value' => 'open', 'label' => 'Open'], ['value' => 'closed', 'label' => 'Closed']])];
            $defaults += ['year' => now()->year, 'starts_on' => now()->startOfYear()->format('Y-m-d'), 'ends_on' => now()->endOfYear()->format('Y-m-d'), 'status' => 'open'];
        }

        return $this->form($label, 'master.save', $fields, $defaults);
    }

    private function itemForm(?Item $item = null): array
    {
        return $this->form($item ? 'Edit item' : 'Add catalog item', 'master.save', [$this->field('sku', 'Item code / SKU'), $this->field('name', 'Item name'), $this->select('item_category_id', 'Category', 'category'), $this->select('unit_of_measure_id', 'Unit of measurement', 'unit'), $this->field('description', 'Description', 'textarea', false), $this->field('specifications', 'Specifications', 'textarea', false), $this->field('item_type', 'Item type', 'select', true, [['value' => 'consumable', 'label' => 'Consumable'], ['value' => 'equipment', 'label' => 'Equipment'], ['value' => 'supply', 'label' => 'Supply']]), $this->field('minimum_stock', 'Minimum stock', 'number'), $this->field('reorder_point', 'Reorder point', 'number'), $this->field('standard_cost', 'Standard unit cost', 'number'), $this->select('supplier_id', 'Preferred supplier', 'supplier', false), $this->field('is_active', 'Active item', 'checkbox', false)], ($item?->toArray() ?? []) + ['kind' => 'item', 'item_type' => 'consumable', 'minimum_stock' => '0', 'reorder_point' => '0', 'standard_cost' => '0', 'is_active' => true]);
    }

    private function catalog(): array
    {
        [$items, $pagination] = $this->listing(Item::with('category', 'unit', 'supplier'), ['sku', 'name'], false);
        $data = $this->page('catalog', 'Item catalog', 'One shared catalog, with fund ownership preserved in every transaction.', ['sku' => 'Item code', 'name' => 'Item name', 'category' => 'Category', 'unit' => 'Unit', 'standard_cost' => ['Standard cost', 'money'], 'reorder_point' => ['Reorder point', 'number'], 'status' => ['Status', 'status']], $items->map(fn ($i) => ['id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'category' => $i->category->name, 'unit' => $i->unit->name, 'standard_cost' => $i->standard_cost, 'reorder_point' => $i->reorder_point, 'status' => $i->is_active ? 'active' : 'inactive', 'details' => ['description' => $i->description, 'specifications' => $i->specifications, 'preferred_supplier' => $i->supplier?->name], 'actions' => $this->can('master-data.manage') ? [$this->action('Edit item', $this->itemForm($i))] : []])->all(), $pagination);
        if ($this->can('master-data.manage')) {
            $data['createForm'] = $this->itemForm();
            $data['pageActions'] = [$this->action('Add category', $this->masterForm('category', 'Add category')), $this->action('Add unit', $this->masterForm('unit', 'Add unit')), $this->action('Add supplier', $this->masterForm('supplier', 'Add supplier'))];
        }

        return $data;
    }

    private function planForm(?AnnualProjection $record = null, bool $amend = false): array
    {
        $defaults = $record?->toArray() ?? [];
        if ($record) {
            $defaults['lines'] = $record->lines->map(fn ($l) => $l->only(['item_id', 'quantity', 'unit_price', 'priority', 'planned_month', 'remarks']))->all();
        }
        if ($amend) {
            unset($defaults['id']);
            $defaults['original_projection_id'] = $record->original_projection_id ?? $record->id;
        }

        return $this->form($amend ? 'Create projection amendment' : ($record ? 'Edit projection draft' : 'Create annual projection'), 'projection.save', [...$this->scopeFields(), $this->field('justification', 'Planning justification', 'textarea')], $defaults, [$this->select('item_id', 'Item', 'item'), $this->field('quantity', 'Estimated annual quantity', 'number'), $this->field('unit_price', 'Estimated unit price', 'number'), $this->field('planned_month', 'Planned month (1–12)', 'number', false), $this->field('remarks', 'Line remarks', 'text', false)]);
    }

    private function approvalForms(Model $record, string $operation, bool $quantities = false): array
    {
        $fields = [$this->field('comment', 'Reviewer comment', 'textarea', false, null, 'Required when rejecting, returning, approving unplanned items, or allowing a soft budget warning.')];
        $actions = [];
        foreach (['approved' => 'Approve', 'returned' => 'Return for revision', 'rejected' => 'Reject'] as $decision => $label) {
            $decisionFields = $fields;
            $decisionFields[0]['required'] = $decision !== 'approved';
            $defaults = ['id' => $record->id, 'decision' => $decision];
            $lineFields = [];
            if ($quantities && $decision === 'approved') {
                $defaults['lines'] = $record->lines->map(fn ($l) => ['line_id' => $l->id, 'approved_quantity' => $l->quantity])->all();
                $lineFields = [$this->field('line_id', 'Request line ID', 'select', true, $record->lines->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->item->name.' · requested '.$l->quantity])->all()), $this->field('approved_quantity', 'Approved quantity', 'number')];
            }
            $actions[] = $this->action($label, $this->form($label, $operation, $decisionFields, $defaults, $lineFields), $decision === 'approved' ? 'primary' : ($decision === 'rejected' ? 'danger' : 'default'));
        }

        return $actions;
    }

    private function projections(): array
    {
        [$records, $pagination] = $this->listing($this->query(AnnualProjection::class, true)->with('fund', 'department', 'fiscalYear', 'lines.item'));
        $rows = $records->map(function ($r) {
            $actions = [];
            if ($this->can('budgets.manage') && in_array($r->status, ['draft', 'returned'], true)) {
                $actions[] = $this->action('Edit draft', $this->planForm($r));
                $actions[] = $this->action('Submit for approval', $this->form('Submit projection', 'projection.submit', [], ['id' => $r->id]), 'primary');
            }
            if ($this->can('budgets.manage') && $r->status === 'approved') {
                $actions[] = $this->action('Create amendment', $this->planForm($r, true));
            }
            if ($this->canFundApprove($r) && $r->status === 'submitted') {
                $actions = [...$actions, ...$this->approvalForms($r, 'projection.decide')];
            }

            return ['id' => $r->id, 'reference' => $r->reference, 'fund' => $r->fund->code, 'department' => $r->department->name, 'year' => $r->fiscalYear->year, 'revision' => $r->revision, 'total' => $r->total, 'status' => $r->status, 'details' => ['justification' => $r->justification, 'original_projection_id' => $r->original_projection_id, 'lines' => $this->lineDetails($r->lines), 'approval_history' => $this->decisions($r)], 'actions' => $actions];
        })->all();
        $data = $this->page('projections', 'Annual projections', 'Approved plans are preserved. Amendments create a new revision without authorizing spending.', ['reference' => 'Reference', 'fund' => 'Fund', 'department' => 'Department', 'year' => 'Year', 'revision' => 'Revision', 'total' => ['Estimated total', 'money'], 'status' => ['Status', 'status']], $rows, $pagination);
        if ($this->can('budgets.manage')) {
            $data['createForm'] = $this->planForm();
        }

        return $data;
    }

    private function canFundApprove(Model $record): bool
    {
        return $this->can('requests.approve') && $this->actor->hasRole(['Fund Approver', 'Super Administrator', 'System Administrator']) && (int) $record->created_by !== $this->actor->id;
    }

    private function decisions(Model $record): array
    {
        return ApprovalDecision::where('subject_type', $record::class)->where('subject_id', $record->id)->with('user')->get()->map(fn ($d) => ['stage' => $d->stage, 'reviewer' => $d->user->name, 'decision' => $d->decision, 'comment' => $d->comment, 'date' => $d->created_at?->toIso8601String()])->all();
    }

    private function lineDetails(Collection $lines): array
    {
        return $lines->map(fn ($l) => ['line_id' => $l->id, 'item' => $l->item->name] + $l->only(['quantity', 'approved_quantity', 'fulfilled_quantity', 'received_quantity', 'unit_price', 'total', 'is_unplanned', 'system_quantity', 'physical_quantity', 'variance', 'variance_reason']))->all();
    }

    private function requestForm(?ItemRequest $record = null): array
    {
        $defaults = ($record?->toArray() ?? []) + ['required_on' => now()->addDays(7)->format('Y-m-d'), 'priority' => 'normal'];
        if ($record) {
            $defaults['lines'] = $record->lines->map(fn ($l) => $l->only(['item_id', 'quantity', 'unit_price']))->all();
        }

        return $this->form($record ? 'Edit request draft' : 'Create item request', 'request.save', [...$this->scopeFields(), $this->field('required_on', 'Required fulfillment date', 'date'), $this->field('purpose', 'Purpose / justification', 'textarea'), $this->field('priority', 'Priority', 'select', true, array_map(fn ($p) => ['value' => $p, 'label' => ucfirst($p)], ['low', 'normal', 'high', 'urgent']))], $defaults, [$this->select('item_id', 'Item', 'item'), $this->field('quantity', 'Requested quantity', 'number'), $this->field('unit_price', 'Estimated unit price', 'number')]);
    }

    private function requestRow(ItemRequest $r): array
    {
        $actions = [];
        $lineOptions = $r->lines->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->item->name.' · outstanding '.Decimal::sub($l->approved_quantity, $l->fulfilled_quantity)])->all();
        if ($this->can('requests.create') && in_array($r->status, ['draft', 'returned'], true)) {
            $actions[] = $this->action('Edit draft', $this->requestForm($r));
            $actions[] = $this->action('Submit request', $this->form('Submit request', 'request.submit', [], ['id' => $r->id]), 'primary');
        }
        $roles = $this->workflow->approvalRoles();
        if ($this->can('requests.approve') && in_array($r->status, ['submitted', 'pending_review'], true) && $this->actor->hasRole($roles[$r->approval_stage] ?? '') && (int) $r->created_by !== $this->actor->id && (($roles[$r->approval_stage] ?? '') !== 'Department Head' || (int) $this->actor->department_id === (int) $r->department_id)) {
            $actions = [...$actions, ...$this->approvalForms($r, 'request.decide', (int) $r->approval_stage === count($roles) - 1)];
        }
        if (in_array($r->status, ['approved', 'partially_fulfilled'], true)) {
            if ($this->can('procurement.manage')) {
                $actions[] = $this->action('Create purchase order', $this->form('Create purchase order', 'purchase.create', [$this->select('supplier_id', 'Supplier', 'supplier'), $this->field('remarks', 'Procurement remarks', 'textarea', false)], ['item_request_id' => $r->id], [$this->field('line_id', 'Request line', 'select', true, $lineOptions), $this->field('quantity', 'Order quantity', 'number'), $this->field('unit_price', 'Actual purchase unit price', 'number')]));
            }
            if ($this->can('inventory.manage')) {
                $actions[] = $this->action('Confirm stock issuance', $this->form('Confirm stock issuance', 'issuance.create', [$this->select('warehouse_id', 'Release location', 'warehouse'), $this->field('recipient', 'Recipient name')], ['id' => $r->id], [$this->field('line_id', 'Request line', 'select', true, $lineOptions), $this->field('quantity', 'Issue quantity', 'number')]), 'primary');
                $actions[] = $this->action('Reserve stock', $this->form('Reserve stock', 'reservation.create', [$this->select('warehouse_id', 'Storage location', 'warehouse'), $this->field('line_id', 'Request line', 'select', true, $lineOptions), $this->field('quantity', 'Reservation quantity', 'number')], ['id' => $r->id]));
            }
        }
        if ($this->can('requests.create') && ! in_array($r->status, ['fulfilled', 'closed', 'canceled'], true)) {
            $actions[] = $this->action('Cancel request', $this->form('Cancel request', 'request.cancel', [$this->field('reason', 'Cancellation reason', 'textarea')], ['id' => $r->id]), 'danger');
        }
        $allocation = BudgetAllocation::where('funding_source_id', $r->funding_source_id)->where('fiscal_year_id', $r->fiscal_year_id)->first();
        $stock = StockBalance::where('funding_source_id', $r->funding_source_id)->whereIn('item_id', $r->lines->pluck('item_id'))->with('item', 'warehouse')->get();

        return ['id' => $r->id, 'reference' => $r->reference, 'fund' => $r->fund->code, 'department' => $r->department->name, 'requester' => $r->creator->name, 'required_on' => $r->required_on, 'estimated_total' => $r->estimated_total, 'planning' => $r->is_unplanned ? 'Unplanned · exception review' : 'Included in annual plan', 'status' => $r->status, 'details' => ['purpose' => $r->purpose, 'priority' => $r->priority, 'next_approval' => $roles[$r->approval_stage] ?? 'Complete', 'budget' => $allocation ? $this->workflow->budget($allocation) : ['available' => '0.00'], 'lines' => $this->lineDetails($r->lines), 'stock' => $stock->map(fn ($b) => ['item' => $b->item->name, 'location' => $b->warehouse->name, 'on_hand' => $b->quantity, 'reserved' => Decimal::sum($b->reservations()->where('status', 'active')->pluck('quantity'), 4)])->all(), 'annual_projections' => AnnualProjection::where('funding_source_id', $r->funding_source_id)->where('fiscal_year_id', $r->fiscal_year_id)->where('department_id', $r->department_id)->where('status', 'approved')->get(['reference', 'revision', 'total'])->toArray(), 'approval_history' => $this->decisions($r)], 'actions' => $actions];
    }

    private function requests(): array
    {
        [$records, $pagination] = $this->listing($this->query(ItemRequest::class, true)->with('fund', 'department', 'creator', 'lines.item'));
        $data = $this->page('requests', 'Monthly requests', 'Requests pass through department and fund approval before fulfillment.', ['reference' => 'Request', 'fund' => 'Fund', 'department' => 'Department', 'requester' => 'Requested by', 'required_on' => ['Required on', 'date'], 'estimated_total' => ['Estimate', 'money'], 'planning' => 'Annual plan', 'status' => ['Status', 'status']], $records->map(fn ($r) => $this->requestRow($r))->all(), $pagination);
        if ($this->can('requests.create')) {
            $data['createForm'] = $this->requestForm();
        }

        return $data;
    }

    private function purchaseRow(PurchaseOrder $r): array
    {
        $actions = [];
        if ($this->canFundApprove($r) && $r->status === 'draft') {
            $actions[] = $this->action('Approve purchase order', $this->form('Approve purchase order', 'purchase.approve', [$this->field('comment', 'Approval / budget exception comment', 'textarea', false)], ['id' => $r->id]), 'primary');
        }
        if ($this->can('inventory.manage') && in_array($r->status, ['ordered', 'partially_received'], true)) {
            $actions[] = $this->action('Receive delivery', $this->form('Receive delivery', 'receipt.create', [$this->select('warehouse_id', 'Receiving location', 'warehouse'), $this->field('delivery_reference', 'Delivery document reference')], ['id' => $r->id], [$this->field('line_id', 'Purchase line', 'select', true, $r->lines->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->item->name.' · outstanding '.Decimal::sub($l->quantity, $l->received_quantity)])->all()), $this->field('quantity', 'Received quantity', 'number')]), 'primary');
        }
        if ($this->can('budgets.manage') && in_array($r->status, ['ordered', 'partially_received', 'received'], true)) {
            $actions[] = $this->action('Record expenditure', $this->form('Record expenditure', 'purchase.spend', [$this->field('amount', 'Actual payment amount', 'number'), $this->field('document_reference', 'Invoice / payment reference'), $this->field('final_payment', 'Final payment · release unused commitment and close', 'checkbox', false)], ['id' => $r->id, 'final_payment' => false]));
        }
        if ($this->can('procurement.manage') && in_array($r->status, ['draft', 'ordered'], true)) {
            $actions[] = $this->action('Cancel order', $this->form('Cancel purchase order', 'purchase.cancel', [$this->field('reason', 'Reason', 'textarea')], ['id' => $r->id]), 'danger');
        }
        $allocation = BudgetAllocation::where('funding_source_id', $r->funding_source_id)->where('fiscal_year_id', $r->fiscal_year_id)->first();

        return ['id' => $r->id, 'reference' => $r->reference, 'request' => $r->request->reference, 'fund' => $r->fund->code, 'supplier' => $r->supplier->name, 'total' => $r->total, 'committed_amount' => $r->committed_amount, 'spent_amount' => $r->spent_amount, 'status' => $r->status, 'details' => ['remarks' => $r->remarks, 'lines' => $this->lineDetails($r->lines), 'budget' => $allocation ? $this->workflow->budget($allocation) : ['available' => '0.00'], 'receipts' => $r->receipts->map(fn ($receipt) => ['reference' => $receipt->reference, 'delivery_document' => $receipt->delivery_reference, 'date' => $receipt->created_at?->toIso8601String()])->all(), 'approval_history' => $this->decisions($r)], 'actions' => $actions];
    }

    private function purchasing(): array
    {
        [$records, $pagination] = $this->listing($this->query(PurchaseOrder::class, true)->with('fund', 'request', 'supplier', 'lines.item', 'receipts'));

        return $this->page('purchasing', 'Procurement & purchasing', 'Approval commits budget. Receiving changes stock. Payments record actual expenditure.', ['reference' => 'Order', 'request' => 'Request', 'fund' => 'Fund', 'supplier' => 'Supplier', 'total' => ['Order total', 'money'], 'committed_amount' => ['Open commitment', 'money'], 'spent_amount' => ['Actual spending', 'money'], 'status' => ['Status', 'status']], $records->map(fn ($r) => $this->purchaseRow($r))->all(), $pagination);
    }

    private function inventory(): array
    {
        $query = $this->query(StockBalance::class)->with('fund', 'item', 'warehouse');
        if ($this->request->filled('q')) {
            $term = '%'.$this->request->input('q').'%';
            $query->whereHas('item', fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term));
        }
        [$records, $pagination] = $this->listing($query, [], false);
        $rows = $records->map(fn ($b) => ['id' => $b->id, 'sku' => $b->item->sku, 'name' => $b->item->name, 'fund' => $b->fund->code, 'location' => $b->warehouse->name, 'quantity' => $b->quantity, 'reserved' => Decimal::sum($b->reservations()->where('status', 'active')->pluck('quantity'), 4), 'status' => Decimal::cmp($b->quantity, $b->item->reorder_point) <= 0 ? 'low_stock' : 'available', 'details' => ['movement_history' => $b->movements()->latest('id')->limit(30)->get()->map(fn ($m) => ['kind' => $m->kind, 'quantity' => $m->quantity_delta, 'balance_after' => $m->balance_after, 'source' => class_basename($m->source_type).' #'.$m->source_id, 'remarks' => $m->remarks, 'date' => $m->created_at?->toIso8601String()])->all()]])->all();
        $issuances = $this->query(StockIssuance::class)->with('lines.item')->latest('id')->limit(20)->get();
        $actions = [$this->action('Transfer stock', $this->form('Transfer stock between locations', 'transfer.create', [$this->select('funding_source_id', 'Fund ownership', 'fund'), $this->select('item_id', 'Item', 'item'), $this->select('warehouse_id', 'Source location', 'warehouse'), $this->select('destination_warehouse_id', 'Destination location', 'warehouse'), $this->field('quantity', 'Quantity', 'number'), $this->field('reason', 'Transfer reason', 'textarea')]))];
        foreach ($issuances as $issue) {
            $actions[] = $this->action('Return · '.$issue->reference, $this->form('Return unused issued stock', 'issuance.return', [$this->field('reason', 'Return reason', 'textarea')], ['id' => $issue->id], [$this->field('line_id', 'Issuance line', 'select', true, $issue->lines->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->item->name.' · issued '.$l->quantity.' · returned '.$l->returned_quantity])->all()), $this->field('quantity', 'Return quantity', 'number')]));
        }

        return $this->page('inventory', 'Stock & movements', 'Fund-owned quantities and an immutable movement ledger. Issue against approved requests.', ['sku' => 'Code', 'name' => 'Item', 'fund' => 'Fund', 'location' => 'Location', 'quantity' => ['On hand', 'number'], 'reserved' => ['Reserved', 'number'], 'status' => ['Stock status', 'status']], $rows, $pagination) + ['pageActions' => $actions];
    }

    private function countRow(InventoryCount $r): array
    {
        $actions = [];
        if ($this->can('inventory.manage') && (int) $r->counter_id === $this->actor->id && in_array($r->status, ['counting', 'returned'], true)) {
            $actions[] = $this->action('Encode / recount', $this->form('Save physical counts', 'count.encode', [], ['id' => $r->id, 'lines' => $r->lines->map(fn ($l) => ['line_id' => $l->id, 'physical_quantity' => $l->physical_quantity ?? '', 'variance_reason' => $l->variance_reason ?? ''])->all()], [$this->field('line_id', 'Count item', 'select', true, $r->lines->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->item->name.' · system at cutoff '.$l->system_quantity])->all()), $this->field('physical_quantity', 'Physical count at cutoff', 'number', true, null, 'Reconcile later movements on the manual count sheet before encoding.'), $this->field('variance_reason', 'Discrepancy reason', 'text', false)]));
            $actions[] = $this->action('Submit count', $this->form('Submit count for review', 'count.submit', [], ['id' => $r->id]), 'primary');
        }
        if ($this->can('counts.approve') && $r->status === 'submitted' && (int) $r->created_by !== $this->actor->id && (int) $r->counter_id !== $this->actor->id) {
            $actions = [...$actions, ...$this->approvalForms($r, 'count.decide')];
        }
        if ($this->can('inventory.manage') && $r->status === 'approved') {
            $actions[] = $this->action('Reconcile approved variance', $this->form('Apply approved reconciliation', 'count.reconcile', [], ['id' => $r->id]), 'primary');
        }

        return ['id' => $r->id, 'reference' => $r->reference, 'fund' => $r->fund->code, 'location' => $r->warehouse->name, 'month' => $r->count_month, 'counter' => $r->counter->name, 'cutoff' => $r->cutoff_at?->toIso8601String(), 'status' => $r->status, 'details' => ['lines' => $this->lineDetails($r->lines), 'approval_history' => $this->decisions($r), 'reconciled_at' => $r->reconciled_at?->toIso8601String(), 'control' => 'Counts preserve a cutoff snapshot. Reconciliation applies only approved variance to the current balance, preserving later movements.'], 'actions' => $actions];
    }

    private function counts(): array
    {
        [$records, $pagination] = $this->listing($this->query(InventoryCount::class)->with('fund', 'warehouse', 'counter', 'lines.item'));
        $users = User::where('is_active', true)->whereHas('fundingSources', fn ($q) => $q->whereIn('funding_sources.id', Access::fundIds($this->actor)))->permission('inventory.manage')->get()->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])->all();

        return $this->page('counts', 'Physical inventory', 'Count first, review discrepancies, then explicitly reconcile approved variances.', ['reference' => 'Count', 'fund' => 'Fund', 'location' => 'Location', 'month' => ['Count month', 'date'], 'counter' => 'Assigned counter', 'cutoff' => ['Snapshot cutoff', 'date'], 'status' => ['Status', 'status']], $records->map(fn ($r) => $this->countRow($r))->all(), $pagination) + ['createForm' => $this->form('Start monthly count', 'count.create', [...$this->scopeFields(false), $this->select('warehouse_id', 'Counting location', 'warehouse'), $this->field('counter_id', 'Assigned counter', 'select', true, $users), $this->field('count_month', 'Counting month (first day)', 'date')], ['count_month' => now()->startOfMonth()->format('Y-m-d')])];
    }

    private function approvals(): array
    {
        $rows = [];
        if ($this->can('requests.approve')) {
            foreach ($this->query(ItemRequest::class, true)->whereIn('status', ['submitted', 'pending_review'])->with('fund', 'department', 'creator', 'lines.item')->get() as $request) {
                $row = $this->requestRow($request);
                $row['kind'] = 'Monthly request';
                $row['amount'] = $request->estimated_total;
                $row['id'] = 'request-'.$request->id;
                $rows[] = $row;
            }
        }
        if ($this->actor->hasRole(['Fund Approver', 'Super Administrator', 'System Administrator'])) {
            foreach ($this->query(AnnualProjection::class, true)->where('status', 'submitted')->with('lines.item')->get() as $record) {
                $rows[] = ['id' => 'projection-'.$record->id, 'kind' => 'Projection / amendment', 'reference' => $record->reference, 'fund' => FundingSource::find($record->funding_source_id)->code, 'amount' => $record->total, 'status' => $record->status, 'details' => ['justification' => $record->justification, 'lines' => $this->lineDetails($record->lines), 'approval_history' => $this->decisions($record)], 'actions' => $this->canFundApprove($record) ? $this->approvalForms($record, 'projection.decide') : []];
            }
            foreach ($this->query(PurchaseOrder::class, true)->where('status', 'draft')->with('fund', 'request', 'supplier', 'lines.item', 'receipts')->get() as $record) {
                $row = $this->purchaseRow($record);
                $row['id'] = 'purchase-'.$record->id;
                $row['kind'] = 'Purchase order';
                $row['amount'] = $record->total;
                $rows[] = $row;
            }
            $adjustments = BudgetAdjustment::where('status', 'submitted')->whereHas('allocation', fn ($q) => $q->whereIn('funding_source_id', Access::fundIds($this->actor)))->with('allocation.fund')->get();
            foreach ($adjustments as $record) {
                if ($this->request->filled('fund_id') && (int) $record->allocation->funding_source_id !== $this->request->integer('fund_id')) {
                    continue;
                } $rows[] = ['id' => 'allocation-'.$record->id, 'kind' => 'Budget allocation change', 'reference' => 'ALLOC-'.$record->id, 'fund' => $record->allocation->fund->code, 'amount' => $record->amount, 'status' => $record->status, 'details' => ['reason' => $record->reason, 'current_budget' => $this->workflow->budget($record->allocation)], 'actions' => (int) $record->created_by !== $this->actor->id ? $this->approvalForms($record, 'allocation.decide') : []];
            }
        }
        if ($this->can('counts.approve')) {
            foreach ($this->query(InventoryCount::class)->where('status', 'submitted')->with('fund', 'warehouse', 'counter', 'lines.item')->get() as $record) {
                $row = $this->countRow($record);
                $row['id'] = 'count-'.$record->id;
                $row['kind'] = 'Physical count';
                $row['amount'] = null;
                $rows[] = $row;
            }
        }

        return $this->page('approvals', 'Approval center', 'Review the record, linked lines, budget, stock, and prior decisions before acting.', ['kind' => 'Workflow', 'reference' => 'Reference', 'fund' => 'Fund', 'amount' => ['Amount', 'money'], 'status' => ['Status', 'status']], $rows);
    }

    private function reports(): array
    {
        $rows = [];
        $reports = ['annual-plans' => ['Annual projections and revisions', 'Original and revised approved annual plans'], 'budgets' => ['Budget utilization', 'Allocation, commitments, expenditure, and availability'], 'requests' => ['Monthly requests and approvals', 'Department requisitions and fulfillment status'], 'purchases' => ['Procurement and supplier history', 'Purchase orders, commitments, and actual payments'], 'stock' => ['Current stock and low stock', 'Fund-owned balances by item and location'], 'movements' => ['Inventory movement ledger', 'Receipts, issuances, returns, transfers, and adjustments'], 'issuances' => ['Stock issuances and consumption', 'Recipient and approved request traceability'], 'counts' => ['Physical counts and discrepancies', 'Cutoff quantities, physical counts, variance, and reconciliation'], 'expenditures' => ['Fund-specific actual expenditure', 'Immutable financial ledger and settlement references'], 'audit' => ['Audit trail', 'Significant actions with actor, timestamp, and values']];
        foreach ($reports as $key => [$name, $description]) {
            if ($key === 'audit' && ! $this->can('audit.view')) {
                continue;
            } $actions = [];
            foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label) {
                $actions[] = ['label' => 'Export '.$label, 'href' => '/app/reports/'.$key.'.'.$format.'?'.http_build_query($this->request->only(['fund_id', 'date_from', 'date_to', 'status', 'q', 'fiscal_year_id', 'department_id', 'warehouse_id', 'item_id', 'supplier_id']))];
            } $rows[] = ['id' => $key, 'name' => $name, 'description' => $description, 'actions' => $actions];
        }

        return $this->page('reports', 'Reports & documents', 'Exports respect your funding-source access and selected date range.', ['name' => 'Report', 'description' => 'Includes'], $rows) + ['budgetFunds' => $this->totals()];
    }

    private function audit(): array
    {
        [$records, $pagination] = $this->listing($this->query(AuditLog::class)->with('user', 'fund'), ['action'], false);

        return $this->page('audit', 'Audit trail', 'Append-only records of financial, approval, inventory, and configuration actions.', ['date' => ['Timestamp', 'date'], 'actor' => 'Responsible user', 'fund' => 'Fund', 'action' => 'Action', 'subject' => 'Record', 'reason' => 'Reason'], $records->map(fn ($r) => ['id' => $r->id, 'date' => $r->created_at?->toIso8601String(), 'actor' => $r->user?->name ?? 'System', 'fund' => $r->fund?->code, 'action' => $r->action, 'subject' => class_basename($r->subject_type).' #'.$r->subject_id, 'reason' => $r->reason, 'details' => ['before' => $r->before_values, 'after' => $r->after_values]])->all(), $pagination);
    }

    private function administration(): array
    {
        $rows = [];
        foreach (['fund' => FundingSource::class, 'department' => Department::class, 'warehouse' => Warehouse::class, 'fiscal-year' => FiscalYear::class, 'category' => ItemCategory::class, 'unit' => UnitOfMeasure::class, 'supplier' => Supplier::class] as $kind => $class) {
            $query = $class::query();
            if ($kind === 'fund') {
                $query->whereIn('id', $this->actor->fundingSources()->pluck('funding_sources.id'));
            }
            foreach ($query->get() as $record) {
                $form = $this->masterForm($kind, 'Edit '.$kind);
                $form['defaults'] = $record->toArray() + ['kind' => $kind];
                $form['fields'][] = $this->field('is_active', 'Active', 'checkbox', false);
                $rows[] = ['id' => $kind.'-'.$record->id, 'kind' => ucfirst(str_replace('-', ' ', $kind)), 'name' => $record->name ?? (string) $record->year, 'code' => $record->code ?? '', 'status' => $record->status ?? ($record->is_active ? 'active' : 'inactive'), 'actions' => $this->can('master-data.manage') ? [$this->action('Edit configuration', $form)] : []];
            }
        }
        $actions = [['label' => 'Manage users & roles', 'href' => '/administration/users']];
        if ($this->can('master-data.manage')) {
            foreach (['fund', 'department', 'warehouse', 'fiscal-year', 'category', 'unit', 'supplier'] as $kind) {
                $actions[] = $this->action('Add '.$kind, $this->masterForm($kind, 'Add '.$kind));
            }
        }

        return $this->page('administration', 'Administration', 'Manage reference records, fiscal-year status, and user access.', ['kind' => 'Configuration', 'name' => 'Name', 'code' => 'Code', 'status' => ['Status', 'status']], $rows) + ['pageActions' => $actions];
    }
}
