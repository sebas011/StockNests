<?php

namespace App\Http\Controllers\StockNest;

use App\Domain\Access;
use App\Domain\Decimal;
use App\Domain\OfficeWorkflow;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceController extends Controller
{
    private User $actor;

    /** @var array<string, array<int, array{value: string, label: string}>> */
    private array $options;

    private Request $request;

    /** @var array<string, string> */
    private array $sortColumns = [];

    /** @var array<string, string> */
    private array $acceptedSort = [];

    /** @var Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, ApprovalDecision>> */
    private Collection $decisionGroups;

    /** @var Collection<string, BudgetAllocation> */
    private Collection $allocations;

    /** @var Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, AnnualProjection>> */
    private Collection $planGroups;

    /** @var Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, StockBalance>> */
    private Collection $stockGroups;

    /** @var list<string> */
    private array $approvalRoles;

    public function __construct(private WorkflowService $workflow) {}

    public function dispatch(WorkflowRequest $request, string $operation): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $result = $this->workflow->execute($actor, $operation, $request->validated(), (string) $request->input('idempotency_key'));

        return back()->with([
            'success' => 'Transaction saved: '.$result['reference'],
            'feedback_id' => (string) Str::uuid(),
        ]);
    }

    public function index(Request $request, string $section = 'overview'): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $this->actor = $actor;
        $this->request = $request;
        $this->sortColumns = [];
        $this->acceptedSort = [];
        $this->approvalRoles = OfficeWorkflow::enabled() ? [] : $this->workflow->approvalRoles();
        abort_if(OfficeWorkflow::enabled() && $section === 'approvals', 404);
        $permission = ['overview' => 'dashboard.view', 'funding' => 'budgets.manage', 'catalog' => 'dashboard.view', 'projections' => 'dashboard.view', 'requests' => 'dashboard.view', 'purchasing' => 'procurement.manage', 'inventory' => 'inventory.manage', 'counts' => 'inventory.manage', 'approvals' => 'requests.approve', 'reports' => 'reports.view', 'audit' => 'audit.view', 'administration' => 'users.manage'][$section] ?? null;
        abort_unless($permission !== null, 404);
        if ($section === 'purchasing') {
            $permission = collect(['procurement.manage', 'inventory.manage', 'budgets.manage', 'fund.approve'])->first(fn ($candidate) => $this->can($candidate)) ?? $permission;
        }
        if ($section === 'approvals') {
            $permission = collect(['requests.approve', 'counts.approve', 'budget.approve', 'fund.approve'])->first(fn ($candidate) => $this->can($candidate)) ?? $permission;
        }
        if ($section === 'administration') {
            $permission = collect(['users.manage', 'master-data.manage', 'fiscal.reopen'])->first(fn ($candidate) => $this->can($candidate)) ?? $permission;
        }
        Access::assert($this->actor, $permission);
        $request->validate(['fund_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'string', 'max:50'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'sort' => ['nullable', 'string', 'max:60'], 'direction' => ['nullable', 'in:asc,desc']]);
        if ($request->filled('fund_id')) {
            abort_unless(in_array($request->integer('fund_id'), Access::fundIds($this->actor), true), 403);
        }
        $funds = $this->actor->fundingSources()->where('is_active', true)->get();
        $departments = Department::where('is_active', true);
        if (OfficeWorkflow::enabled()) {
            $departments->whereKey(OfficeWorkflow::departmentId());
        } elseif (Access::departmentLimited($this->actor)) {
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
            default => abort(404),
        };

        return Inertia::render('stocknest', $data + ['officeWorkflow' => OfficeWorkflow::enabled(), 'organization' => OfficeWorkflow::enabled() ? OfficeWorkflow::NAME : config('stocknest.organization'), 'funds' => $funds->map(fn ($f) => ['id' => $f->id, 'label' => $f->code])->all(), 'selectedFund' => (string) $request->input('fund_id', ''), 'filters' => $request->only(['q', 'status', 'fund_id', 'date_from', 'date_to']) + $this->acceptedSort]);
    }

    /**
     * @template TModel of ItemCategory|UnitOfMeasure|Supplier|Warehouse
     *
     * @param  class-string<TModel>  $class
     * @return array<int, array{value: string, label: string}>
     */
    private function lookup(string $class): array
    {
        return (new $class)->newQuery()->where('is_active', true)->orderBy('name')->get()->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->name])->all();
    }

    private function can(string $permission): bool
    {
        return $this->actor->hasPermissionTo($permission);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return Builder<TModel>
     */
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

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $search
     * @return array{0: \Illuminate\Database\Eloquent\Collection<int, TModel>, 1: array<string, int|string|null>}
     */
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
        $this->sortColumns = match ($query->getModel()::class) {
            Item::class => ['sku' => 'sku', 'name' => 'name', 'reorder_point' => 'reorder_point', 'standard_cost' => 'standard_cost'],
            AnnualProjection::class => ['reference' => 'reference', 'revision' => 'revision', 'total' => 'total', 'status' => 'status'],
            ItemRequest::class => ['reference' => 'reference', 'required_on' => 'required_on', 'estimated_total' => 'estimated_total', 'status' => 'status'],
            PurchaseOrder::class => ['reference' => 'reference', 'total' => 'total', 'committed_amount' => 'committed_amount', 'spent_amount' => 'spent_amount', 'status' => 'status'],
            StockBalance::class => ['quantity' => 'quantity'],
            InventoryCount::class => ['reference' => 'reference', 'month' => 'count_month', 'cutoff' => 'cutoff_at', 'status' => 'status'],
            AuditLog::class => ['date' => 'created_at', 'action' => 'action'],
            default => [],
        };
        $requested = (string) $this->request->input('sort', 'id');
        $sort = in_array($requested, $this->sortColumns, true) ? $requested : 'id';
        $direction = $this->request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $this->acceptedSort = ['sort' => $sort, 'direction' => $direction];
        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }
        $result = $query->paginate(15)->withQueryString();

        return [$result->getCollection(), ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total(), 'prev_page_url' => $result->previousPageUrl(), 'next_page_url' => $result->nextPageUrl()]];
    }

    /**
     * @param  array<string, string|array{string, string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int|string|null>|null  $pagination
     * @return array<string, mixed>
     */
    private function page(string $key, string $title, string $subtitle, array $columns, array $rows, ?array $pagination = null): array
    {
        $cols = [];
        foreach ($columns as $keyName => $definition) {
            [$label, $format] = is_array($definition) ? $definition : [$definition, 'text'];
            $cols[] = array_filter(['key' => $keyName, 'label' => $label, 'format' => $format, 'sortKey' => $this->sortColumns[$keyName] ?? null], fn ($value) => $value !== null);
        }

        return ['page' => ['key' => $key, 'title' => $title, 'subtitle' => $subtitle, 'columns' => $cols, 'rows' => $rows, 'pagination' => $pagination]];
    }

    /**
     * @param  array<int, array{value: string, label: string}>|null  $options
     * @return array<string, mixed>
     */
    private function field(string $name, string $label, string $type = 'text', bool $required = true, ?array $options = null, ?string $description = null): array
    {
        $numeric = $type === 'number';
        $integer = in_array($name, ['year', 'planned_month', 'revision'], true);

        return array_filter(['name' => $name, 'label' => $label, 'type' => $type, 'required' => $required, 'options' => $options, 'step' => $numeric ? ($integer ? '1' : (str_contains($name, 'price') || in_array($name, ['amount', 'standard_cost'], true) ? '0.01' : '0.0001')) : null, 'min' => $numeric ? ($name === 'year' ? 2000 : ($name === 'planned_month' ? 1 : 0)) : null, 'max' => $name === 'planned_month' ? 12 : ($name === 'year' ? 2200 : null), 'description' => $description], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private function select(string $name, string $label, string $options, bool $required = true): array
    {
        return $this->field($name, $label, 'select', $required, $this->options[$options]);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $defaults
     * @param  list<array<string, mixed>>  $lineFields
     * @return array<string, mixed>
     */
    private function form(string $title, string $operation, array $fields = [], array $defaults = [], array $lineFields = [], bool $fixedLines = false): array
    {
        $context = array_intersect_key($defaults, array_flip(['id', 'kind', 'decision', 'item_request_id', 'original_projection_id']));

        if ($operation === 'allocation.create') {
            $fields = array_map(function ($field) {
                if ($field['name'] === 'amount') {
                    unset($field['min']);
                }

                return $field;
            }, $fields);
        }

        return ['title' => $title, 'action' => '/app/actions/'.$operation, 'method' => 'post', 'fields' => $fields, 'defaults' => $defaults, 'context' => $context, 'lineFields' => $lineFields, 'fixedLines' => $fixedLines, 'submitLabel' => $title];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function action(string $title, array $form, string $tone = 'default'): array
    {
        return ['label' => $title, 'tone' => $tone, 'form' => $form];
    }

    /** @return list<array<string, mixed>> */
    private function totals(?int $year = null): array
    {
        $funds = [];
        $allocations = $this->query(BudgetAllocation::class)->when($year, fn ($q) => $q->whereHas('fiscalYear', fn ($fy) => $fy->where('year', $year)))->with('fund', 'fiscalYear')
            ->withSum('entries as committed', 'commitment_delta')->withSum('entries as spent', 'expenditure_delta')->get();
        $approvedPlans = $this->query(AnnualProjection::class, true)->where('status', 'approved')
            ->when($year, fn ($q) => $q->whereHas('fiscalYear', fn ($fy) => $fy->where('year', $year)))
            ->with('fund', 'fiscalYear')->get()->groupBy(fn ($plan) => $plan->funding_source_id.'-'.$plan->fiscal_year_id);
        foreach ($allocations as $allocation) {
            $projections = $approvedPlans->get($allocation->funding_source_id.'-'.$allocation->fiscal_year_id, (new AnnualProjection)->newCollection());
            $original = Decimal::sum($projections->whereNull('original_projection_id')->pluck('total'));
            $revised = Decimal::sum($projections->groupBy(fn ($p) => $p->original_projection_id ?? $p->id)->map(fn ($versions) => $versions->sortByDesc('revision')->first()->total));
            $funds[] = ['id' => $allocation->id, 'name' => $allocation->fund->code.' · '.$allocation->fiscalYear->year, 'original' => $original, 'revised' => $revised] + $this->allocationBudget($allocation);
        }
        foreach ($approvedPlans as $versions) {
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

    /** @return array<string, mixed> */
    private function overview(): array
    {
        $funds = $this->totals((int) now()->year);
        $recent = $this->can('audit.view') ? $this->query(AuditLog::class)->with('user')->latest('id')->limit(8)->get() : collect();
        $requestQuery = $this->query(ItemRequest::class, true);
        $pending = OfficeWorkflow::enabled()
            ? (clone $requestQuery)->where('channel', 'supply')->whereIn('status', ['submitted', 'partially_received'])->count()
            : (clone $requestQuery)->whereIn('status', ['submitted', 'pending_review'])->count();
        $balances = $this->query(StockBalance::class)->with('item')->get();
        $metrics = [
            ['label' => 'Authorized allocation', 'value' => Decimal::sum(array_column($funds, 'allocation')), 'format' => 'money', 'helper' => 'Approved spending authority'],
            ['label' => 'Committed', 'value' => Decimal::sum(array_column($funds, 'committed')), 'format' => 'money', 'helper' => 'Open purchase obligations'],
            ['label' => 'Actual expenditure', 'value' => Decimal::sum(array_column($funds, 'spent')), 'format' => 'money', 'helper' => 'Recorded payments'],
            ['label' => 'Available budget', 'value' => Decimal::sum(array_column($funds, 'available')), 'format' => 'money', 'helper' => 'Allocation less obligations and spending'],
            ['label' => OfficeWorkflow::enabled() ? 'Awaiting Supply' : 'Pending requests', 'value' => $pending, 'helper' => OfficeWorkflow::enabled() ? 'Submitted requests awaiting all items' : 'Awaiting review'],
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
        $officeData = [];
        if (OfficeWorkflow::enabled()) {
            $fundId = $this->request->filled('fund_id') ? $this->request->integer('fund_id') : null;
            $yearId = FiscalYear::where('year', $chartYear)->value('id');
            $officeData = ['allowanceRows' => $yearId !== null ? OfficeWorkflow::allowanceRows($this->actor, $fundId, (int) $yearId) : [], 'monthlyStats' => OfficeWorkflow::monthlyRows($this->actor, $fundId, $chartYear)];
        }

        return $this->page('overview', 'Your inventory, in balance.', OfficeWorkflow::enabled() ? 'Track approved annual items, Supply requests, outside purchases, and office stock.' : 'Live fund obligations, stock, and approvals in one workspace.', ['fund' => 'Funding source', 'original' => ['Original plan', 'money'], 'revised' => ['Revised plan', 'money'], 'allocation' => ['Allocation', 'money'], 'committed' => ['Committed', 'money'], 'spent' => ['Spent', 'money'], 'available' => ['Available', 'money']], array_map(fn ($f) => ['fund' => $f['name']] + $f, $funds)) + ['fiscalYear' => $chartYear, 'metrics' => $metrics, 'budgetFunds' => $funds, 'chartData' => $chart, 'activity' => $recent->map(fn ($a) => ['id' => $a->id, 'title' => str_replace('.', ' · ', $a->action), 'description' => ($a->user->name ?? 'System').' · record '.$a->subject_id, 'date' => $a->created_at?->toIso8601String(), 'type' => Str::before($a->action, '.')])->all(), 'notices' => count($funds) ? [] : [['title' => 'Set up your annual allocations', 'description' => OfficeWorkflow::enabled() ? 'Encode the funding already approved for your office, then add the annual items approved by Supply.' : 'Budget officers submit allocation changes; a separate fund approver authorizes them.', 'tone' => 'info']]] + $officeData;
    }

    /** @return list<array<string, mixed>> */
    private function scopeFields(bool $department = true): array
    {
        $fields = [$this->select('funding_source_id', 'Funding source', 'fund'), $this->select('fiscal_year_id', 'Fiscal year', 'year')];
        if ($department && ! OfficeWorkflow::enabled()) {
            $fields[] = $this->select('department_id', 'Department', 'department');
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function funding(): array
    {
        if (OfficeWorkflow::enabled()) {
            $funds = $this->totals();

            return $this->page('funding', 'Funding & budgets', 'Record approved GAA and APPCSE funding. Outside purchases and payments remain separately traceable.', ['fund' => 'Fund / fiscal year', 'original' => ['Original plan', 'money'], 'revised' => ['Current plan', 'money'], 'allocation' => ['Authorized', 'money'], 'committed' => ['Committed', 'money'], 'spent' => ['Spent', 'money'], 'available' => ['Available', 'money']], array_map(fn ($fund) => ['fund' => $fund['name']] + $fund, $funds)) + ['budgetFunds' => $funds, 'createForm' => $this->form('Record approved allocation', 'allocation.create', [...$this->scopeFields(false), $this->field('amount', 'Amount to add / reduce', 'number', true, null, 'Enter the approved change. A negative amount reduces the allocation.'), $this->field('reason', 'Approved funding reference / authority', 'textarea')])];
        }

        $funds = $this->totals();
        $adjustments = BudgetAdjustment::whereHas('allocation', fn ($q) => $q->whereIn('funding_source_id', Access::fundIds($this->actor)))->with('allocation.fund')->latest()->limit(30)->get();

        return $this->page('funding', 'Funding & budgets', 'GAA and APPCSE remain independently traceable. Allocations require separate approval.', ['fund' => 'Fund / fiscal year', 'original' => ['Original plan', 'money'], 'revised' => ['Revised plan', 'money'], 'allocation' => ['Authorized', 'money'], 'committed' => ['Committed', 'money'], 'spent' => ['Spent', 'money'], 'available' => ['Available', 'money']], array_map(fn ($f) => ['fund' => $f['name'], 'details' => ['allocation_changes' => $adjustments->where('budget_allocation_id', $f['id'])->map(fn ($a) => ['id' => $a->id, 'amount' => $a->amount, 'reason' => $a->reason, 'status' => $a->status])->values()->all()]] + $f, $funds)) + ['budgetFunds' => $funds, 'createForm' => $this->form('Submit allocation change', 'allocation.create', [...$this->scopeFields(false), $this->field('amount', 'Amount to add / reduce', 'number', true, null, 'Negative values reduce the allocation. Changes require fund approver approval.'), $this->field('reason', 'Authority / reason', 'textarea')])];
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function itemForm(?Item $item = null): array
    {
        return $this->form($item ? 'Edit item' : 'Add catalog item', 'master.save', [$this->field('sku', 'Item code / SKU'), $this->field('name', 'Item name'), $this->select('item_category_id', 'Category', 'category'), $this->select('unit_of_measure_id', 'Unit of measurement', 'unit'), $this->field('description', 'Description', 'textarea', false), $this->field('specifications', 'Specifications', 'textarea', false), $this->field('item_type', 'Item type', 'select', true, [['value' => 'consumable', 'label' => 'Consumable'], ['value' => 'equipment', 'label' => 'Equipment'], ['value' => 'supply', 'label' => 'Supply']]), $this->field('minimum_stock', 'Minimum stock', 'number'), $this->field('reorder_point', 'Reorder point', 'number'), $this->field('standard_cost', 'Standard unit cost', 'number'), $this->select('supplier_id', 'Preferred supplier', 'supplier', false), $this->field('is_active', 'Active item', 'checkbox', false)], ($item?->toArray() ?? []) + ['kind' => 'item', 'item_type' => 'consumable', 'minimum_stock' => '0', 'reorder_point' => '0', 'standard_cost' => '0', 'is_active' => true]);
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function planForm(?AnnualProjection $record = null, bool $amend = false): array
    {
        $defaults = $record?->toArray() ?? [];
        if ($record) {
            $defaults['lines'] = $record->lines->map(fn ($l) => $l->only(['item_id', 'quantity', 'unit_price', 'priority', 'planned_month', 'remarks']))->all();
        }
        if ($amend) {
            unset($defaults['id']);
            $defaults['original_projection_id'] = $record->original_projection_id ?? $record->getKey();
        }

        if (OfficeWorkflow::enabled()) {
            return $this->form($amend ? 'Record approved plan revision' : 'Encode approved annual items', 'projection.save', [...$this->scopeFields(), $this->field('justification', 'Supply approval reference / details', 'textarea')], $defaults, [$this->select('item_id', 'Approved item', 'item'), $this->field('quantity', 'Approved annual quantity', 'number'), $this->field('unit_price', 'Approved estimated unit price', 'number'), $this->field('planned_month', 'Planned month (1–12)', 'number', false), $this->field('remarks', 'Line remarks', 'text', false)]);
        }

        return $this->form($amend ? 'Create projection amendment' : ($record ? 'Edit projection draft' : 'Create annual projection'), 'projection.save', [...$this->scopeFields(), $this->field('justification', 'Planning justification', 'textarea')], $defaults, [$this->select('item_id', 'Item', 'item'), $this->field('quantity', 'Estimated annual quantity', 'number'), $this->field('unit_price', 'Estimated unit price', 'number'), $this->field('planned_month', 'Planned month (1–12)', 'number', false), $this->field('remarks', 'Line remarks', 'text', false)]);
    }

    /** @return list<array<string, mixed>> */
    private function approvalForms(Model $record, string $operation, bool $quantities = false): array
    {
        $fields = [$this->field('comment', 'Reviewer comment', 'textarea', false, null, 'Required when rejecting, returning, approving unplanned items, or allowing a soft budget warning.')];
        $actions = [];
        foreach (['approved' => 'Approve', 'returned' => 'Return for revision', 'rejected' => 'Reject'] as $decision => $label) {
            $decisionFields = $fields;
            $decisionFields[0]['required'] = $decision !== 'approved';
            $defaults = ['id' => $record->getKey(), 'decision' => $decision];
            $lineFields = [];
            if ($quantities && $decision === 'approved' && $record instanceof ItemRequest) {
                $defaults['lines'] = $record->lines->map(fn ($l) => ['line_id' => $l->id, 'item' => $l->item->name, 'requested_quantity' => $l->quantity, 'approved_quantity' => $l->quantity])->all();
                $lineFields = [$this->field('line_id', 'Request line ID', 'hidden'), $this->field('item', 'Item') + ['readOnly' => true], $this->field('requested_quantity', 'Requested quantity', 'number') + ['readOnly' => true], $this->field('approved_quantity', 'Approved quantity', 'number')];
            }
            $actions[] = $this->action($label, $this->form($label, $operation, $decisionFields, $defaults, $lineFields, $quantities && $decision === 'approved'), $decision === 'approved' ? 'primary' : ($decision === 'rejected' ? 'danger' : 'default'));
        }

        return $actions;
    }

    /** @return array<string, mixed> */
    private function projections(): array
    {
        if (OfficeWorkflow::enabled()) {
            [$records, $pagination] = $this->listing($this->query(AnnualProjection::class, true)->with('fund', 'fiscalYear', 'lines.item'));
            $rows = $records->map(fn ($record) => ['id' => $record->id, 'reference' => $record->reference, 'fund' => $record->fund->code, 'year' => $record->fiscalYear->year, 'revision' => $record->revision, 'total' => $record->total, 'status' => $record->status, 'details' => ['approval_reference' => $record->justification, 'lines' => $this->lineDetails($record->lines)], 'actions' => $this->can('budgets.manage') && $record->status === 'approved' ? [$this->action('Record approved revision', $this->planForm($record, true))] : []])->all();
            $data = $this->page('projections', 'Approved annual items', 'Encode the annual items already approved by Supply. Submitted Supply requests reduce the remaining allowance; outside purchases are separate.', ['reference' => 'Reference', 'fund' => 'Fund', 'year' => 'Year', 'revision' => 'Revision', 'total' => ['Estimated total', 'money'], 'status' => ['Status', 'status']], $rows, $pagination);
            if ($this->can('budgets.manage')) {
                $data['createForm'] = $this->planForm();
            }

            return $data + ['allowanceRows' => OfficeWorkflow::allowanceRows($this->actor, $this->request->filled('fund_id') ? $this->request->integer('fund_id') : null)];
        }

        [$records, $pagination] = $this->listing($this->query(AnnualProjection::class, true)->with('fund', 'department', 'fiscalYear', 'lines.item'));
        $this->prepareContext($records);
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
        return $this->can($record instanceof PurchaseOrder ? 'fund.approve' : 'budget.approve') && (int) $record->getAttribute('created_by') !== $this->actor->id;
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $records
     */
    private function prepareContext(Collection $records): void
    {
        $this->decisionGroups = ApprovalDecision::query()->where(function ($query) use ($records) {
            foreach ($records->groupBy(fn ($record) => $record::class) as $class => $group) {
                $query->orWhere(fn ($subject) => $subject->where('subject_type', $class)->whereIn('subject_id', $group->pluck('id')));
            }
            if ($records->isEmpty()) {
                $query->whereRaw('1 = 0');
            }
        })->with('user')->orderBy('id')->get()->groupBy(fn ($decision) => $decision->subject_type.':'.$decision->subject_id);
        $funds = $records->pluck('funding_source_id')->filter()->unique();
        $years = $records->pluck('fiscal_year_id')->filter()->unique();
        $this->allocations = BudgetAllocation::whereIn('funding_source_id', $funds)->whereIn('fiscal_year_id', $years)
            ->withSum('entries as committed', 'commitment_delta')->withSum('entries as spent', 'expenditure_delta')
            ->get()->keyBy(fn ($allocation) => $allocation->funding_source_id.':'.$allocation->fiscal_year_id);
        $requests = $records->filter(fn ($record) => $record instanceof ItemRequest);
        $this->planGroups = AnnualProjection::whereIn('funding_source_id', $requests->pluck('funding_source_id'))->whereIn('fiscal_year_id', $requests->pluck('fiscal_year_id'))
            ->whereIn('department_id', $requests->pluck('department_id'))->where('status', 'approved')->get(['id', 'funding_source_id', 'fiscal_year_id', 'department_id', 'reference', 'revision', 'total'])
            ->groupBy(fn ($plan) => $plan->funding_source_id.':'.$plan->fiscal_year_id.':'.$plan->department_id);
        $this->stockGroups = StockBalance::whereIn('funding_source_id', $requests->pluck('funding_source_id'))->whereIn('item_id', $requests->flatMap(fn ($record) => $record->lines->pluck('item_id'))->unique())
            ->with('item', 'warehouse')->withSum(['reservations as reserved' => fn ($query) => $query->where('status', 'active')], 'quantity')
            ->get()->groupBy('funding_source_id');
    }

    /** @return array<string, mixed> */
    private function recordBudget(Model $record): array
    {
        $allocation = $this->allocations->get($record->getAttribute('funding_source_id').':'.$record->getAttribute('fiscal_year_id'));
        if (! $allocation) {
            return ['available' => '0.00'];
        }

        return $this->allocationBudget($allocation);
    }

    /** @return array<string, mixed> */
    private function allocationBudget(BudgetAllocation $allocation): array
    {
        $committed = Decimal::add((string) ($allocation->getAttribute('committed') ?? '0'), '0', 2);
        $spent = Decimal::add((string) ($allocation->getAttribute('spent') ?? '0'), '0', 2);

        return ['allocation' => $allocation->authorized_amount, 'committed' => $committed, 'spent' => $spent, 'available' => Decimal::sub(Decimal::sub($allocation->authorized_amount, $committed, 2), $spent, 2)];
    }

    /** @return Collection<int, ApprovalDecision> */
    private function decisionHistory(Model $record): Collection
    {
        return $this->decisionGroups->get($record::class.':'.$record->getKey(), (new ApprovalDecision)->newCollection());
    }

    /** @return array<int, array<string, mixed>> */
    private function decisions(Model $record): array
    {
        return $this->decisionHistory($record)->map(fn ($d) => ['stage' => $d->stage, 'reviewer' => $d->user->name, 'decision' => $d->decision, 'comment' => $d->comment, 'date' => $d->created_at?->toIso8601String()])->all();
    }

    /**
     * @template TLine of \App\Models\ProjectionLine|\App\Models\ItemRequestLine|\App\Models\PurchaseOrderLine|\App\Models\InventoryCountLine
     *
     * @param  Collection<int, TLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function lineDetails(Collection $lines): array
    {
        return $lines->map(fn ($l) => ['line_id' => $l->id, 'item' => $l->item->name] + $l->only(['quantity', 'approved_quantity', 'fulfilled_quantity', 'received_quantity', 'unit_price', 'total', 'is_unplanned', 'system_quantity', 'physical_quantity', 'variance', 'variance_reason']))->all();
    }

    /** @return array<string, mixed> */
    private function requestForm(?ItemRequest $record = null): array
    {
        $defaults = ($record?->toArray() ?? []) + ['required_on' => now()->addDays(7)->format('Y-m-d'), 'priority' => 'normal', 'channel' => 'supply'];
        if ($record) {
            $defaults['lines'] = $record->lines->map(fn ($l) => $l->only(['item_id', 'quantity', 'unit_price']))->all();
        }

        if (OfficeWorkflow::enabled()) {
            return $this->form($record ? 'Edit request draft' : 'Create item request', 'request.save', [...$this->scopeFields(), $this->field('channel', 'Request type', 'select', true, [['value' => 'supply', 'label' => 'Request items from Supply'], ['value' => 'cash', 'label' => 'Cash request for outside purchase']]), $this->field('required_on', 'Needed by', 'date'), $this->field('purpose', 'Purpose / reason Supply cannot provide the items', 'textarea')], $defaults, [$this->select('item_id', 'Item', 'item'), $this->field('quantity', 'Requested quantity', 'number'), $this->field('unit_price', 'Estimated unit price', 'number')]);
        }

        return $this->form($record ? 'Edit request draft' : 'Create item request', 'request.save', [...$this->scopeFields(), $this->field('required_on', 'Required fulfillment date', 'date'), $this->field('purpose', 'Purpose / justification', 'textarea'), $this->field('priority', 'Priority', 'select', true, array_map(fn ($p) => ['value' => $p, 'label' => ucfirst($p)], ['low', 'normal', 'high', 'urgent']))], $defaults, [$this->select('item_id', 'Item', 'item'), $this->field('quantity', 'Requested quantity', 'number'), $this->field('unit_price', 'Estimated unit price', 'number')]);
    }

    /** @return array<string, mixed> */
    private function requestRow(ItemRequest $r): array
    {
        if (OfficeWorkflow::enabled()) {
            return $this->officeRequestRow($r);
        }

        $actions = [];
        $canEditRequest = $this->can('requests.create') && ((int) $r->created_by === $this->actor->id || $this->can('users.manage'));
        $lineOptions = $r->lines->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->item->name.' · outstanding '.Decimal::sub($l->approved_quantity, $l->fulfilled_quantity)])->all();
        if ($canEditRequest && in_array($r->status, ['draft', 'returned'], true)) {
            $actions[] = $this->action('Edit draft', $this->requestForm($r));
            $actions[] = $this->action('Submit request', $this->form('Submit request', 'request.submit', [], ['id' => $r->id]), 'primary');
        }
        $roles = $this->approvalRoles;
        if ($this->workflow->canReviewRequestStage($this->actor, $r, $this->decisionHistory($r), $roles)) {
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
        if ($canEditRequest && ! in_array($r->status, ['fulfilled', 'closed', 'canceled', 'rejected'], true)) {
            $actions[] = $this->action('Cancel request', $this->form('Cancel request', 'request.cancel', [$this->field('reason', 'Cancellation reason', 'textarea')], ['id' => $r->id]), 'danger');
        }
        $stock = $this->stockGroups->get($r->funding_source_id, collect())->whereIn('item_id', $r->lines->pluck('item_id'))->values();

        return ['id' => $r->id, 'reference' => $r->reference, 'fund' => $r->fund->code, 'department' => $r->department->name, 'requester' => $r->creator->name, 'required_on' => $r->required_on, 'estimated_total' => $r->estimated_total, 'planning' => $r->is_unplanned ? 'Unplanned · exception review' : 'Included in annual plan', 'status' => $r->status, 'details' => ['purpose' => $r->purpose, 'priority' => $r->priority, 'next_approval' => $roles[$r->approval_stage] ?? 'Complete', 'budget' => $this->recordBudget($r), 'lines' => $this->lineDetails($r->lines), 'stock' => $stock->map(fn ($b) => ['item' => $b->item->name, 'location' => $b->warehouse->name, 'on_hand' => $b->quantity, 'reserved' => Decimal::add((string) ($b->reserved ?? '0'), '0', 4)])->all(), 'annual_projections' => $this->planGroups->get($r->funding_source_id.':'.$r->fiscal_year_id.':'.$r->department_id, collect())->map(fn ($plan) => $plan->only(['reference', 'revision', 'total']))->all(), 'approval_history' => $this->decisions($r)], 'actions' => $actions];
    }

    /** @return array<string, mixed> */
    private function officeRequestRow(ItemRequest $record): array
    {
        $actions = [];
        $supply = $record->getAttribute('channel') === 'supply';
        $purchaseOptions = [];
        $hasOutsideOrders = false;
        $state = $record->status;
        if (! $supply) {
            $orders = $record->purchaseOrders->where('status', '<>', 'canceled');
            $hasOutsideOrders = $orders->isNotEmpty();
            $orderedQuantities = [];
            foreach ($orders as $order) {
                foreach ($order->lines as $purchaseLine) {
                    $lineId = (int) $purchaseLine->item_request_line_id;
                    $orderedQuantities[$lineId] = Decimal::add($orderedQuantities[$lineId] ?? '0', $purchaseLine->quantity);
                }
            }
            foreach ($record->lines as $line) {
                $remaining = Decimal::sub($line->quantity, $orderedQuantities[$line->id] ?? '0');
                if (Decimal::cmp($remaining, '0') > 0) {
                    $purchaseOptions[] = ['value' => (string) $line->id, 'label' => $line->item->name.' · not yet ordered '.$remaining];
                }
            }
            $state = match ($record->status) {
                'approved' => $hasOutsideOrders ? 'outside_ordered' : 'cash_requested',
                'partially_fulfilled' => 'partially_received',
                'fulfilled' => 'received',
                default => $record->status,
            };
        }
        $canEdit = $this->can('requests.create') && ((int) $record->created_by === $this->actor->id || $this->can('users.manage'));
        if ($canEdit && in_array($record->status, ['draft', 'returned'], true)) {
            $actions[] = $this->action('Edit draft', $this->requestForm($record));
            $label = $supply ? 'Submit to Supply' : 'Record cash request';
            $actions[] = $this->action($label, $this->form($label, 'request.submit', [], ['id' => $record->id]), 'primary');
        }
        if ($supply && $this->can('inventory.manage') && in_array($record->status, ['submitted', 'partially_received'], true)) {
            $lines = $record->lines->filter(fn ($line) => Decimal::cmp($line->quantity, (string) $line->getAttribute('received_quantity')) > 0);
            $actions[] = $this->action('Record Supply receipt', $this->form('Record Supply receipt', 'supply.receive', [$this->select('warehouse_id', 'Office storage location', 'warehouse'), $this->field('delivery_reference', 'Supply issuance / delivery reference')], ['id' => $record->id], [$this->field('line_id', 'Requested item', 'select', true, $lines->map(fn ($line) => ['value' => (string) $line->id, 'label' => $line->item->name.' · awaiting '.Decimal::sub($line->quantity, (string) $line->getAttribute('received_quantity'))])->values()->all()), $this->field('quantity', 'Quantity received', 'number')]), 'primary');
        }
        if (! $supply && $purchaseOptions !== [] && $this->can('procurement.manage') && in_array($record->status, ['approved', 'partially_fulfilled'], true)) {
            $actions[] = $this->action('Record authorized outside purchase', $this->form('Record authorized outside purchase', 'purchase.create', [$this->select('supplier_id', 'Outside supplier', 'supplier'), $this->field('authorization_reference', 'Approved cash request / authority reference'), $this->field('remarks', 'Purchase details', 'textarea', false)], ['item_request_id' => $record->id], [$this->field('line_id', 'Requested item', 'select', true, $purchaseOptions), $this->field('quantity', 'Purchase quantity', 'number'), $this->field('unit_price', 'Actual purchase unit price', 'number')]), 'primary');
        }
        if ($canEdit && ! in_array($record->status, ['fulfilled', 'closed', 'canceled', 'rejected'], true)) {
            $actions[] = $this->action('Cancel request', $this->form('Cancel request', 'request.cancel', [$this->field('reason', 'Cancellation reason', 'textarea')], ['id' => $record->id]), 'danger');
        }

        return ['id' => $record->id, 'reference' => $record->reference, 'fund' => $record->fund->code, 'channel' => $supply ? 'Supply items' : 'Outside cash purchase', 'requester' => $record->creator->name, 'required_on' => $record->required_on, 'estimated_total' => $record->estimated_total, 'status' => $record->status, 'state' => $state, 'details' => ['purpose' => $record->purpose, 'allowance_tracking' => $supply ? 'Submitted quantities use the approved annual allowance. Receipts increase office stock separately.' : 'Tracked separately from the Supply allowance. Record the external cash authority when entering the purchase.', 'lines' => $this->lineDetails($record->lines)], 'actions' => $actions];
    }

    /** @return array<string, mixed> */
    private function officeInventory(): array
    {
        $query = $this->query(StockBalance::class)->with('fund', 'item', 'warehouse')->with(['movements' => fn ($query) => $query->latest('id')->limit(30)]);
        if ($this->request->filled('q')) {
            $term = '%'.$this->request->input('q').'%';
            $query->whereHas('item', fn ($query) => $query->where('name', 'like', $term)->orWhere('sku', 'like', $term));
        }
        [$records, $pagination] = $this->listing($query, [], false);
        $rows = $records->map(fn ($balance) => ['id' => $balance->id, 'sku' => $balance->item->sku, 'name' => $balance->item->name, 'fund' => $balance->fund->code, 'location' => $balance->warehouse->name, 'quantity' => $balance->quantity, 'status' => Decimal::cmp($balance->quantity, $balance->item->reorder_point) <= 0 ? 'low_stock' : 'available', 'details' => ['movement_history' => $balance->movements->map(fn ($movement) => ['kind' => $movement->kind, 'quantity' => $movement->quantity_delta, 'balance_after' => $movement->balance_after, 'remarks' => $movement->remarks, 'date' => $movement->created_at?->toIso8601String()])->all()], 'actions' => Decimal::cmp($balance->quantity, '0') > 0 ? [$this->action('Record office use', $this->form('Record office use', 'stock.consume', [$this->field('quantity', 'Quantity used', 'number'), $this->field('recipient', 'Used by / recipient'), $this->field('reason', 'Purpose / reason', 'textarea')], ['id' => $balance->id]))] : []])->all();

        return $this->page('inventory', 'Office inventory', 'Supply receipts and outside purchases add stock. Record office use and physical counts to keep quantities current.', ['sku' => 'Code', 'name' => 'Item', 'fund' => 'Fund', 'location' => 'Location', 'quantity' => ['On hand', 'number'], 'status' => ['Stock status', 'status']], $rows, $pagination);
    }

    /** @return array<string, mixed> */
    private function requests(): array
    {
        $query = $this->query(ItemRequest::class, true)->with('fund', 'department', 'creator', 'lines.item');
        if (OfficeWorkflow::enabled()) {
            $query->with('purchaseOrders.lines');
        }
        [$records, $pagination] = $this->listing($query);
        $this->prepareContext($records);
        if (OfficeWorkflow::enabled()) {
            $data = $this->page('requests', 'Item requests', 'Submit requests to Supply against the annual allowance. Record receipts separately. Cash requests for outside purchases do not use the Supply allowance.', ['reference' => 'Request', 'fund' => 'Fund', 'channel' => 'Request type', 'requester' => 'Requested by', 'required_on' => ['Needed by', 'date'], 'estimated_total' => ['Estimate', 'money'], 'state' => ['Status', 'status']], $records->map(fn ($record) => $this->requestRow($record))->all(), $pagination);
            if ($this->can('requests.create')) {
                $data['createForm'] = $this->requestForm();
            }

            return $data;
        }
        $data = $this->page('requests', 'Monthly requests', 'Requests pass through department and fund approval before fulfillment.', ['reference' => 'Request', 'fund' => 'Fund', 'department' => 'Department', 'requester' => 'Requested by', 'required_on' => ['Required on', 'date'], 'estimated_total' => ['Estimate', 'money'], 'planning' => 'Annual plan', 'status' => ['Status', 'status']], $records->map(fn ($r) => $this->requestRow($r))->all(), $pagination);
        if ($this->can('requests.create')) {
            $data['createForm'] = $this->requestForm();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function purchaseRow(PurchaseOrder $r): array
    {
        $actions = [];
        if (! OfficeWorkflow::enabled() && $this->canFundApprove($r) && $r->status === 'draft') {
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

        return ['id' => $r->id, 'reference' => $r->reference, 'request' => $r->request->reference, 'fund' => $r->fund->code, 'supplier' => $r->supplier->name, 'total' => $r->total, 'committed_amount' => $r->committed_amount, 'spent_amount' => $r->spent_amount, 'status' => $r->status, 'details' => ['remarks' => $r->remarks, 'authorization_reference' => $r->getAttribute('authorization_reference'), 'lines' => $this->lineDetails($r->lines), 'budget' => $this->recordBudget($r), 'receipts' => $r->receipts->map(fn ($receipt) => ['reference' => $receipt->reference, 'delivery_document' => $receipt->delivery_reference, 'date' => $receipt->created_at?->toIso8601String()])->all(), 'approval_history' => $this->decisions($r)], 'actions' => $actions];
    }

    /** @return array<string, mixed> */
    private function purchasing(): array
    {
        [$records, $pagination] = $this->listing($this->query(PurchaseOrder::class, true)->with('fund', 'request', 'supplier', 'lines.item', 'receipts'));
        $this->prepareContext($records);

        return $this->page('purchasing', OfficeWorkflow::enabled() ? 'Outside purchases' : 'Procurement & purchasing', OfficeWorkflow::enabled() ? 'Record externally authorized cash purchases, receive the items, and record actual payments. Supply allowances remain unchanged.' : 'Approval commits budget. Receiving changes stock. Payments record actual expenditure.', ['reference' => 'Order', 'request' => 'Request', 'fund' => 'Fund', 'supplier' => 'Supplier', 'total' => ['Order total', 'money'], 'committed_amount' => ['Open commitment', 'money'], 'spent_amount' => ['Actual spending', 'money'], 'status' => ['Status', 'status']], $records->map(fn ($r) => $this->purchaseRow($r))->all(), $pagination);
    }

    /** @return array<string, mixed> */
    private function inventory(): array
    {
        if (OfficeWorkflow::enabled()) {
            return $this->officeInventory();
        }

        $query = $this->query(StockBalance::class)->with('fund', 'item', 'warehouse')->with(['movements' => fn ($query) => $query->latest('id')->limit(30)])->withSum(['reservations as reserved' => fn ($query) => $query->where('status', 'active')], 'quantity');
        if ($this->request->filled('q')) {
            $term = '%'.$this->request->input('q').'%';
            $query->whereHas('item', fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term));
        }
        [$records, $pagination] = $this->listing($query, [], false);
        $rows = $records->map(fn ($b) => ['id' => $b->id, 'sku' => $b->item->sku, 'name' => $b->item->name, 'fund' => $b->fund->code, 'location' => $b->warehouse->name, 'quantity' => $b->quantity, 'reserved' => Decimal::add((string) ($b->reserved ?? '0'), '0', 4), 'status' => Decimal::cmp($b->quantity, $b->item->reorder_point) <= 0 ? 'low_stock' : 'available', 'details' => ['movement_history' => $b->movements->map(fn ($m) => ['kind' => $m->kind, 'quantity' => $m->quantity_delta, 'balance_after' => $m->balance_after, 'source' => class_basename($m->source_type).' #'.$m->source_id, 'remarks' => $m->remarks, 'date' => $m->created_at?->toIso8601String()])->all()]])->all();
        $issuances = $this->query(StockIssuance::class)->with('lines.item')->latest('id')->limit(20)->get();
        $actions = [$this->action('Transfer stock', $this->form('Transfer stock between locations', 'transfer.create', [$this->select('funding_source_id', 'Fund ownership', 'fund'), $this->select('fiscal_year_id', 'Fiscal year', 'year'), $this->field('effective_on', 'Transfer date', 'date'), $this->select('item_id', 'Item', 'item'), $this->select('warehouse_id', 'Source location', 'warehouse'), $this->select('destination_warehouse_id', 'Destination location', 'warehouse'), $this->field('quantity', 'Quantity', 'number'), $this->field('reason', 'Transfer reason', 'textarea')]))];
        foreach ($issuances as $issue) {
            $actions[] = $this->action('Return · '.$issue->reference, $this->form('Return unused issued stock', 'issuance.return', [$this->field('reason', 'Return reason', 'textarea')], ['id' => $issue->id], [$this->field('line_id', 'Issuance line', 'select', true, $issue->lines->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->item->name.' · issued '.$l->quantity.' · returned '.$l->returned_quantity])->all()), $this->field('quantity', 'Return quantity', 'number')]));
        }

        return $this->page('inventory', 'Stock & movements', 'Fund-owned quantities and an immutable movement ledger. Issue against approved requests.', ['sku' => 'Code', 'name' => 'Item', 'fund' => 'Fund', 'location' => 'Location', 'quantity' => ['On hand', 'number'], 'reserved' => ['Reserved', 'number'], 'status' => ['Stock status', 'status']], $rows, $pagination) + ['pageActions' => $actions];
    }

    /** @return array<string, mixed> */
    private function countRow(InventoryCount $r): array
    {
        $actions = [];
        if ($this->can('inventory.manage') && (int) $r->counter_id === $this->actor->id && in_array($r->status, ['counting', 'returned'], true)) {
            $actions[] = $this->action('Encode / recount', $this->form('Save physical counts', 'count.encode', [], ['id' => $r->id, 'lines' => $r->lines->map(fn ($l) => ['line_id' => $l->id, 'physical_quantity' => $l->physical_quantity ?? '', 'variance_reason' => $l->variance_reason ?? ''])->all()], [$this->field('line_id', 'Count item', 'select', true, $r->lines->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->item->name.' · system at cutoff '.$l->system_quantity])->all()), $this->field('physical_quantity', 'Physical count at cutoff', 'number', true, null, 'Reconcile later movements on the manual count sheet before encoding.'), $this->field('variance_reason', 'Discrepancy reason', 'text', false)]));
            $actions[] = $this->action(OfficeWorkflow::enabled() ? 'Finalize count' : 'Submit count', $this->form(OfficeWorkflow::enabled() ? 'Finalize physical count' : 'Submit count for review', 'count.submit', [], ['id' => $r->id]), 'primary');
        }
        if (! OfficeWorkflow::enabled() && $this->can('counts.approve') && $r->status === 'submitted' && (int) $r->created_by !== $this->actor->id && (int) $r->counter_id !== $this->actor->id) {
            $actions = [...$actions, ...$this->approvalForms($r, 'count.decide')];
        }
        if ($this->can('inventory.manage') && $r->status === 'approved') {
            $actions[] = $this->action(OfficeWorkflow::enabled() ? 'Reconcile stock variance' : 'Reconcile approved variance', $this->form(OfficeWorkflow::enabled() ? 'Apply stock reconciliation' : 'Apply approved reconciliation', 'count.reconcile', [], ['id' => $r->id]), 'primary');
        }

        return ['id' => $r->id, 'reference' => $r->reference, 'fund' => $r->fund->code, 'location' => $r->warehouse->name, 'month' => $r->count_month, 'counter' => $r->counter->name, 'cutoff' => $r->cutoff_at?->toIso8601String(), 'status' => $r->status, 'details' => ['lines' => $this->lineDetails($r->lines), 'approval_history' => $this->decisions($r), 'reconciled_at' => $r->reconciled_at?->toIso8601String(), 'control' => OfficeWorkflow::enabled() ? 'The count keeps its cutoff snapshot. Finalize it before confirming reconciliation; later stock movements are preserved.' : 'Counts preserve a cutoff snapshot. Reconciliation applies only approved variance to the current balance, preserving later movements.'], 'actions' => $actions];
    }

    /** @return array<string, mixed> */
    private function counts(): array
    {
        [$records, $pagination] = $this->listing($this->query(InventoryCount::class)->with('fund', 'warehouse', 'counter', 'lines.item'));
        $this->prepareContext($records);
        $users = User::where('is_active', true)->whereHas('fundingSources', fn ($q) => $q->whereIn('funding_sources.id', Access::fundIds($this->actor)))->permission('inventory.manage')->get()->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])->all();

        return $this->page('counts', 'Physical inventory', OfficeWorkflow::enabled() ? 'Count office stock, explain discrepancies, finalize the count, then confirm any stock reconciliation.' : 'Count first, review discrepancies, then explicitly reconcile approved variances.', ['reference' => 'Count', 'fund' => 'Fund', 'location' => 'Location', 'month' => ['Count month', 'date'], 'counter' => 'Assigned counter', 'cutoff' => ['Snapshot cutoff', 'date'], 'status' => ['Status', 'status']], $records->map(fn ($r) => $this->countRow($r))->all(), $pagination) + ['createForm' => $this->form('Start monthly count', 'count.create', [...$this->scopeFields(false), $this->select('warehouse_id', 'Counting location', 'warehouse'), $this->field('counter_id', 'Assigned counter', 'select', true, $users), $this->field('count_month', 'Counting month (first day)', 'date')], ['count_month' => now()->startOfMonth()->format('Y-m-d')])];
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return Builder<TModel>
     */
    private function unscopedQuery(string $class): Builder
    {
        return (new $class)->newQuery();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $amount
     * @param  literal-string  $reference
     */
    private function approvalQuery(string $type, Builder $query, string $amount, string $reference = 'reference'): \Illuminate\Database\Query\Builder
    {
        return $query->select('id', 'created_at', 'status')->selectRaw("? AS kind, {$amount} AS amount, {$reference} AS reference", [$type])->toBase();
    }

    /** @return array<string, mixed> */
    private function approvals(): array
    {
        $queries = [];
        if ($this->can('requests.approve')) {
            $queries[] = $this->approvalQuery('request', $this->query(ItemRequest::class, true)->whereIn('status', ['submitted', 'pending_review']), 'estimated_total');
        }
        if ($this->can('budget.approve')) {
            $queries[] = $this->approvalQuery('projection', $this->query(AnnualProjection::class, true)->where('status', 'submitted'), 'total');
            $adjustments = BudgetAdjustment::where('status', 'submitted')->whereHas('allocation', function ($query) {
                $query->whereIn('funding_source_id', Access::fundIds($this->actor));
                if ($this->request->filled('fund_id')) {
                    $query->where('funding_source_id', $this->request->integer('fund_id'));
                }
            });
            foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
                if ($this->request->filled($field)) {
                    $adjustments->whereDate('created_at', $operator, $this->request->input($field));
                }
            }
            $queries[] = $this->approvalQuery('allocation', $adjustments, 'amount', "'ALLOC-' || CAST(id AS VARCHAR)");
        }
        if ($this->can('fund.approve')) {
            $queries[] = $this->approvalQuery('purchase', $this->query(PurchaseOrder::class, true)->where('status', 'draft'), 'total');
        }
        if ($this->can('counts.approve')) {
            $queries[] = $this->approvalQuery('count', $this->query(InventoryCount::class)->where('status', 'submitted'), 'NULL');
        }
        $this->sortColumns = ['kind' => 'kind', 'reference' => 'reference', 'amount' => 'amount', 'status' => 'status'];
        if (! $queries) {
            return $this->page('approvals', 'Approval center', 'Review pending records before acting.', ['kind' => 'Workflow', 'reference' => 'Reference', 'fund' => 'Fund', 'amount' => ['Amount', 'money'], 'status' => ['Status', 'status']], []);
        }
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }
        $feed = DB::query()->fromSub($union, 'pending_approvals');
        if ($this->request->filled('q')) {
            $feed->where('reference', 'like', '%'.$this->request->input('q').'%');
        }
        if ($this->request->filled('status')) {
            $feed->where('status', $this->request->input('status'));
        }
        $requested = (string) $this->request->input('sort', 'created_at');
        $sort = in_array($requested, $this->sortColumns, true) ? $requested : 'created_at';
        $direction = $this->request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $this->acceptedSort = ['sort' => $sort, 'direction' => $direction];
        $result = $feed->orderBy($sort, $direction)->orderBy('kind')->orderBy('id', $direction)->paginate(15)->withQueryString();
        $classes = ['request' => ItemRequest::class, 'projection' => AnnualProjection::class, 'purchase' => PurchaseOrder::class, 'allocation' => BudgetAdjustment::class, 'count' => InventoryCount::class];
        $relations = ['request' => ['fund', 'department', 'creator', 'lines.item'], 'projection' => ['fund', 'lines.item'], 'purchase' => ['fund', 'request', 'supplier', 'lines.item', 'receipts'], 'allocation' => ['allocation.fund'], 'count' => ['fund', 'warehouse', 'counter', 'lines.item']];
        $records = collect();
        foreach ($result->getCollection()->groupBy('kind') as $kind => $entries) {
            $class = $classes[$kind];
            foreach ($this->unscopedQuery($class)->whereIn('id', $entries->pluck('id'))->with($relations[$kind])->get() as $record) {
                if ($record instanceof BudgetAdjustment) {
                    $record->setAttribute('funding_source_id', $record->allocation->funding_source_id);
                    $record->setAttribute('fiscal_year_id', $record->allocation->fiscal_year_id);
                }
                $records->put($kind.':'.$record->getKey(), $record);
            }
        }
        $this->prepareContext($records->values());
        $rows = $result->getCollection()->map(function ($entry) use ($records) {
            $record = $records->get($entry->kind.':'.$entry->id);
            $row = match ($entry->kind) {
                'request' => $this->requestRow($record),
                'purchase' => $this->purchaseRow($record),
                'count' => $this->countRow($record),
                'projection' => ['reference' => $record->getAttribute('reference'), 'fund' => $record->fund->code, 'status' => $record->getAttribute('status'), 'details' => ['justification' => $record->justification, 'lines' => $this->lineDetails($record->lines), 'approval_history' => $this->decisions($record)], 'actions' => $this->canFundApprove($record) ? $this->approvalForms($record, 'projection.decide') : []],
                'allocation' => ['reference' => 'ALLOC-'.$record->getKey(), 'fund' => $record->allocation->fund->code, 'status' => $record->getAttribute('status'), 'details' => ['reason' => $record->reason, 'current_budget' => $this->recordBudget($record)], 'actions' => (int) $record->getAttribute('created_by') !== $this->actor->id ? $this->approvalForms($record, 'allocation.decide') : []],
                default => abort(404),
            };
            $row['id'] = $entry->kind.'-'.$record->getKey();
            $row['kind'] = ['request' => 'Monthly request', 'projection' => 'Projection / amendment', 'purchase' => 'Purchase order', 'allocation' => 'Budget allocation change', 'count' => 'Physical count'][$entry->kind];
            $row['amount'] = $entry->amount;

            return $row;
        })->all();
        $pagination = ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total(), 'prev_page_url' => $result->previousPageUrl(), 'next_page_url' => $result->nextPageUrl()];

        return $this->page('approvals', 'Approval center', 'Review the record, linked lines, budget, stock, and prior decisions before acting.', ['kind' => 'Workflow', 'reference' => 'Reference', 'fund' => 'Fund', 'amount' => ['Amount', 'money'], 'status' => ['Status', 'status']], $rows, $pagination);
    }

    /** @return array<string, mixed> */
    private function reports(): array
    {
        $rows = [];
        $reports = ['annual-plans' => ['Annual projections and revisions', 'Original and revised approved annual plans'], 'budgets' => ['Budget utilization', 'Allocation, commitments, expenditure, and availability'], 'requests' => ['Monthly requests and approvals', 'Department requisitions and fulfillment status'], 'purchases' => ['Procurement and supplier history', 'Purchase orders, commitments, and actual payments'], 'stock' => ['Current stock and low stock', 'Fund-owned balances by item and location'], 'movements' => ['Inventory movement ledger', 'Receipts, issuances, returns, transfers, and adjustments'], 'issuances' => ['Stock issuances and consumption', 'Recipient and approved request traceability'], 'counts' => ['Physical counts and discrepancies', 'Cutoff quantities, physical counts, variance, and reconciliation'], 'expenditures' => ['Fund-specific actual expenditure', 'Immutable financial ledger and settlement references'], 'audit' => ['Audit trail', 'Significant actions with actor, timestamp, and values']];
        if (OfficeWorkflow::enabled()) {
            $reports = ['allowance' => ['Annual Supply allowance', 'Approved, submitted, received, and remaining quantities for every item'], 'monthly-items' => ['Monthly item activity', 'Supply requests and receipts, cash requests, and outside purchase receipts by month']] + $reports;
            $reports['requests'] = ['Supply and cash requests', 'Office requests, quantities, and receipt status'];
            $reports['purchases'] = ['Outside purchase history', 'Externally authorized purchases, receipts, and payments'];
            $reports['movements'] = ['Office stock movement history', 'Supply receipts, outside purchases, office use, and count reconciliation'];
            unset($reports['issuances']);
        }
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

    /** @return array<string, mixed> */
    private function audit(): array
    {
        [$records, $pagination] = $this->listing($this->query(AuditLog::class)->with('user', 'fund'), ['action'], false);

        return $this->page('audit', 'Audit trail', 'Append-only records of financial, approval, inventory, and configuration actions.', ['date' => ['Timestamp', 'date'], 'actor' => 'Responsible user', 'fund' => 'Fund', 'action' => 'Action', 'subject' => 'Record', 'reason' => 'Reason'], $records->map(fn ($r) => ['id' => $r->id, 'date' => $r->created_at?->toIso8601String(), 'actor' => $r->user->name ?? 'System', 'fund' => $r->fund?->code, 'action' => $r->action, 'subject' => class_basename($r->subject_type).' #'.$r->subject_id, 'reason' => $r->reason, 'details' => ['before' => $r->before_values, 'after' => $r->after_values]])->all(), $pagination);
    }

    /** @return array<string, mixed> */
    private function administration(): array
    {
        $rows = [];
        $configuration = ['fund' => FundingSource::class, 'department' => Department::class, 'warehouse' => Warehouse::class, 'fiscal-year' => FiscalYear::class, 'category' => ItemCategory::class, 'unit' => UnitOfMeasure::class, 'supplier' => Supplier::class];
        if (OfficeWorkflow::enabled()) {
            unset($configuration['department']);
        }
        foreach ($configuration as $kind => $class) {
            $query = $class::query();
            if ($kind === 'fund') {
                $query->whereIn('id', $this->actor->fundingSources()->pluck('funding_sources.id'));
            }
            foreach ($query->get() as $record) {
                $form = $this->masterForm($kind, 'Edit '.$kind);
                $form['defaults'] = $record->toArray() + ['kind' => $kind];
                $form['fields'][] = $this->field('is_active', 'Active', 'checkbox', false);
                if ($kind === 'fiscal-year' && $record->getAttribute('status') === 'closed') {
                    $form['fields'] = array_map(function ($field) {
                        if ($field['name'] === 'status') {
                            $field['options'] = [['value' => 'closed', 'label' => 'Closed · use Reopen fiscal year']];
                        }

                        return $field;
                    }, $form['fields']);
                }
                $recordActions = $this->can('master-data.manage') ? [$this->action('Edit configuration', $form)] : [];
                if ($kind === 'fiscal-year' && $record->getAttribute('status') === 'closed' && $this->can('fiscal.reopen')) {
                    $recordActions[] = $this->action('Reopen fiscal year', $this->form('Reopen fiscal year', 'fiscal-year.reopen', [$this->field('reason', 'Reason for reopening', 'textarea')], ['id' => $record->getKey()]), 'primary');
                }
                $rows[] = ['id' => $kind.'-'.$record->getKey(), 'kind' => ucfirst(str_replace('-', ' ', $kind)), 'name' => $record->name ?? (string) $record->getAttribute('year'), 'code' => $record->code ?? '', 'status' => $record->getAttribute('status') ?? ($record->is_active ? 'active' : 'inactive'), 'actions' => $recordActions];
            }
        }
        $actions = $this->can('users.manage') ? [['label' => 'Manage users & roles', 'href' => '/administration/users']] : [];
        if ($this->can('master-data.manage')) {
            foreach (array_keys($configuration) as $kind) {
                $actions[] = $this->action('Add '.$kind, $this->masterForm($kind, 'Add '.$kind));
            }
        }

        return $this->page('administration', 'Administration', 'Manage reference records, fiscal-year status, and user access.', ['kind' => 'Configuration', 'name' => 'Name', 'code' => 'Code', 'status' => ['Status', 'status']], $rows) + ['pageActions' => $actions];
    }
}
