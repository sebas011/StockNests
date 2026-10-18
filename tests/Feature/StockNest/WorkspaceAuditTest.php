<?php

use App\Models\AnnualProjection;
use App\Models\ApprovalDecision;
use App\Models\AuditLog;
use App\Models\BudgetAdjustment;
use App\Models\BudgetAllocation;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\InventoryCount;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemRequest;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $this->workspaceFund = FundingSource::where('code', 'GAA')->firstOrFail();
    $this->workspaceYear = FiscalYear::firstOrFail();
    $this->workspaceDepartment = Department::where('code', 'OPS')->firstOrFail();
    $this->workspaceActor = User::factory()->create(['is_active' => true, 'department_id' => $this->workspaceDepartment->id]);
    $this->workspaceActor->assignRole('Super Administrator');
    $this->workspaceActor->fundingSources()->sync([$this->workspaceFund->id]);
    $this->workspaceCreator = User::factory()->create(['is_active' => true, 'department_id' => $this->workspaceDepartment->id]);
    $this->workspaceItem = Item::create(['sku' => 'WORKSPACE-PAPER', 'name' => 'Workspace paper', 'item_category_id' => ItemCategory::firstOrFail()->id, 'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id, 'standard_cost' => '10.00']);
    $this->workspaceSupplier = Supplier::create(['code' => 'WORKSPACE-SUP', 'name' => 'Workspace supplier']);
    $this->workspaceAllocation = BudgetAllocation::create(['funding_source_id' => $this->workspaceFund->id, 'fiscal_year_id' => $this->workspaceYear->id, 'authorized_amount' => '10000.00']);
});

function workspaceRequest($test, int $number, array $attributes = []): ItemRequest
{
    $record = ItemRequest::create($attributes + [
        'reference' => 'WORKSPACE-REQ-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
        'funding_source_id' => $test->workspaceFund->id, 'fiscal_year_id' => $test->workspaceYear->id,
        'department_id' => $test->workspaceDepartment->id, 'created_by' => $test->workspaceCreator->id,
        'required_on' => now()->addWeek()->toDateString(), 'purpose' => 'Workspace regression', 'priority' => 'normal',
        'status' => 'submitted', 'approval_stage' => 1, 'estimated_total' => '20.00',
    ]);
    $record->lines()->create(['item_id' => $test->workspaceItem->id, 'quantity' => '2', 'unit_price' => '10.00']);

    return $record;
}

function workspacePurchase($test, ItemRequest $request, int $number): PurchaseOrder
{
    $order = PurchaseOrder::create([
        'reference' => 'WORKSPACE-PO-'.$number, 'item_request_id' => $request->id, 'supplier_id' => $test->workspaceSupplier->id,
        'funding_source_id' => $test->workspaceFund->id, 'fiscal_year_id' => $test->workspaceYear->id,
        'department_id' => $test->workspaceDepartment->id, 'created_by' => $test->workspaceCreator->id, 'total' => '20.00',
    ]);
    $order->lines()->create(['item_request_line_id' => $request->lines->first()->id, 'item_id' => $test->workspaceItem->id, 'quantity' => '2', 'unit_price' => '10.00', 'total' => '20.00']);

    return $order;
}

function workspacePage($test, string $url): array
{
    return $test->actingAs($test->workspaceActor)->get($url)->assertOk()->viewData('page')['props'];
}

test('workspace sorting exposes only supported columns and reports the accepted fallback', function () {
    workspaceRequest($this, 1);
    $props = workspacePage($this, '/app/requests?sort=name&direction=asc');
    expect($props['filters']['sort'])->toBe('id');
    $columns = collect($props['page']['columns'])->keyBy('key');
    expect($columns['reference']['sortKey'])->toBe('reference')
        ->and($columns['required_on']['sortKey'])->toBe('required_on')
        ->and($columns['fund'])->not->toHaveKey('sortKey')
        ->and($columns['planning'])->not->toHaveKey('sortKey');
    $earlier = workspaceRequest($this, 2, ['required_on' => now()->toDateString()]);
    $sorted = workspacePage($this, '/app/requests?sort=required_on&direction=asc');
    expect($sorted['page']['rows'][0]['id'])->toBe($earlier->id)
        ->and($sorted['filters']['sort'])->toBe('required_on');
    $catalog = workspacePage($this, '/app/catalog?sort=sku&direction=asc');
    expect($catalog['filters']['sort'])->toBe('sku')
        ->and(collect($catalog['page']['columns'])->firstWhere('key', 'category'))->not->toHaveKey('sortKey');
});

test('request approval quantities retain immutable line identity and display context', function () {
    $request = workspaceRequest($this, 1);
    $props = workspacePage($this, '/app/requests');
    $approve = collect($props['page']['rows'][0]['actions'])->first(fn ($action) => ($action['form']['defaults']['decision'] ?? '') === 'approved')['form'];
    $fields = collect($approve['lineFields'])->keyBy('name');
    expect($approve['fixedLines'])->toBeTrue()
        ->and($fields['line_id']['type'])->toBe('hidden')
        ->and($fields['item']['readOnly'])->toBeTrue()
        ->and($fields['requested_quantity']['readOnly'])->toBeTrue()
        ->and($fields['approved_quantity']['min'])->toBe(0)
        ->and($approve['defaults']['lines'][0]['line_id'])->toBe($request->lines->first()->id)
        ->and($approve['defaults']['lines'][0]['item'])->toBe('Workspace paper');
});

test('workspace approval actions use explicit grants and enforce reviewer separation', function () {
    $request = workspaceRequest($this, 1);
    $this->workspaceActor->syncRoles([]);
    $this->workspaceActor->givePermissionTo(['dashboard.view', 'requests.approve']);
    $props = workspacePage($this, '/app/requests');
    expect($props['page']['rows'][0]['actions'])->toBe([]);
    $this->workspaceActor->givePermissionTo('fund.approve');
    $props = workspacePage($this, '/app/requests');
    expect(collect($props['page']['rows'][0]['actions'])->pluck('label'))->toContain('Approve');
    ApprovalDecision::create(['subject_type' => ItemRequest::class, 'subject_id' => $request->id, 'funding_source_id' => $this->workspaceFund->id, 'user_id' => $this->workspaceActor->id, 'stage' => 1, 'decision' => 'approved', 'created_at' => now()]);
    $props = workspacePage($this, '/app/requests');
    expect($props['page']['rows'][0]['actions'])->toBe([]);
});

test('budget-only custom approvers can access projection and allocation decisions', function () {
    $this->workspaceActor->syncRoles([]);
    $this->workspaceActor->givePermissionTo('budget.approve');
    BudgetAdjustment::create(['budget_allocation_id' => $this->workspaceAllocation->id, 'created_by' => $this->workspaceCreator->id, 'amount' => '100', 'reason' => 'Authority']);
    $props = workspacePage($this, '/app/approvals');
    expect($props['page']['pagination']['total'])->toBe(1)
        ->and(collect($props['page']['rows'][0]['actions'])->pluck('label'))->toContain('Approve');
});

test('numeric metadata permits signed allocation changes but bounds months and quantities', function () {
    $funding = workspacePage($this, '/app/funding');
    expect(collect($funding['createForm']['fields'])->firstWhere('name', 'amount'))->not->toHaveKey('min');
    $plans = workspacePage($this, '/app/projections');
    $fields = collect($plans['createForm']['lineFields'])->keyBy('name');
    expect($fields['quantity']['min'])->toBe(0)
        ->and($fields['unit_price']['min'])->toBe(0)
        ->and($fields['planned_month']['min'])->toBe(1)
        ->and($fields['planned_month']['max'])->toBe(12)
        ->and($fields['planned_month']['step'])->toBe('1');
    $stock = workspacePage($this, '/app/inventory');
    $fields = collect($stock['pageActions'][0]['form']['fields'])->pluck('name');
    expect($fields)->toContain('fiscal_year_id', 'effective_on');
});

test('closed fiscal years expose a controlled reopen form for explicitly permitted users', function () {
    $this->workspaceYear->update(['status' => 'closed']);
    $this->workspaceActor->syncRoles([]);
    $this->workspaceActor->givePermissionTo('fiscal.reopen');
    $props = workspacePage($this, '/app/administration');
    $row = collect($props['page']['rows'])->firstWhere('id', 'fiscal-year-'.$this->workspaceYear->id);
    expect($row['actions'][0]['form']['action'])->toBe('/app/actions/fiscal-year.reopen')
        ->and($row['actions'][0]['form']['context']['id'])->toBe($this->workspaceYear->id)
        ->and($row['actions'][0]['form']['fields'][0]['required'])->toBeTrue();
});

test('fiscal-year reopen form submits through HTTP with a required reason and records its audit', function () {
    $this->workspaceYear->update(['status' => 'closed']);
    $this->workspaceActor->syncRoles([]);
    $this->workspaceActor->givePermissionTo('fiscal.reopen');
    $props = workspacePage($this, '/app/administration');
    $row = collect($props['page']['rows'])->firstWhere('id', 'fiscal-year-'.$this->workspaceYear->id);
    $form = $row['actions'][0]['form'];

    $this->actingAs($this->workspaceActor)->postJson($form['action'], $form['context'] + ['idempotency_key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect($this->workspaceYear->fresh()->status)->toBe('closed')
        ->and(AuditLog::where('action', 'fiscal-year.reopened')->count())->toBe(0);

    $this->from('/app/administration')->post($form['action'], $form['context'] + ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Authorized year-end correction'])
        ->assertRedirect('/app/administration')->assertSessionHasNoErrors();
    $audit = AuditLog::where('action', 'fiscal-year.reopened')->sole();
    expect($this->workspaceYear->fresh()->status)->toBe('open')
        ->and($audit->subject_type)->toBe(FiscalYear::class)
        ->and($audit->subject_id)->toBe($this->workspaceYear->id)
        ->and($audit->user_id)->toBe($this->workspaceActor->id)
        ->and($audit->reason)->toBe('Authorized year-end correction')
        ->and($audit->before_values['status'])->toBe('closed')
        ->and($audit->after_values['status'])->toBe('open');
});

test('fiscal-year reopen HTTP action rejects users without its explicit permission', function () {
    $this->workspaceYear->update(['status' => 'closed']);
    $this->workspaceActor->syncRoles([]);
    $this->workspaceActor->givePermissionTo('master-data.manage');

    $this->actingAs($this->workspaceActor)->postJson('/app/actions/fiscal-year.reopen', ['id' => $this->workspaceYear->id, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Attempted year-end correction'])
        ->assertForbidden();
    expect($this->workspaceYear->fresh()->status)->toBe('closed')
        ->and(AuditLog::where('action', 'fiscal-year.reopened')->count())->toBe(0);
});

test('approval pagination covers all workflow types without dropping later records', function () {
    for ($number = 1; $number <= 6; $number++) {
        $request = workspaceRequest($this, $number);
        workspacePurchase($this, $request, $number);
        AnnualProjection::create(['reference' => 'WORKSPACE-PLAN-'.$number, 'funding_source_id' => $this->workspaceFund->id, 'fiscal_year_id' => $this->workspaceYear->id, 'department_id' => $this->workspaceDepartment->id, 'created_by' => $this->workspaceCreator->id, 'status' => 'submitted', 'total' => '20', 'justification' => 'Test plan']);
        BudgetAdjustment::create(['budget_allocation_id' => $this->workspaceAllocation->id, 'created_by' => $this->workspaceCreator->id, 'amount' => '100', 'reason' => 'Authority']);
        InventoryCount::create(['reference' => 'WORKSPACE-COUNT-'.$number, 'funding_source_id' => $this->workspaceFund->id, 'fiscal_year_id' => $this->workspaceYear->id, 'warehouse_id' => Warehouse::firstOrFail()->id, 'created_by' => $this->workspaceCreator->id, 'counter_id' => $this->workspaceCreator->id, 'count_month' => now()->startOfMonth()->toDateString(), 'cutoff_at' => now(), 'status' => 'submitted']);
    }
    workspaceRequest($this, 999, ['funding_source_id' => FundingSource::where('code', 'APPCSE')->firstOrFail()->id]);
    $first = workspacePage($this, '/app/approvals?sort=reference&direction=asc');
    $second = workspacePage($this, '/app/approvals?sort=reference&direction=asc&page=2');
    $all = collect($first['page']['rows'])->concat($second['page']['rows']);
    expect($first['page']['pagination']['total'])->toBe(30)
        ->and($first['page']['pagination']['last_page'])->toBe(2)
        ->and($first['page']['rows'])->toHaveCount(15)
        ->and($all->pluck('id')->unique())->toHaveCount(30)
        ->and($all->pluck('kind')->unique())->toHaveCount(5);
    $filtered = workspacePage($this, '/app/approvals?q=WORKSPACE-PLAN-&status=submitted');
    expect($filtered['page']['pagination']['total'])->toBe(6);
});

test('request and purchasing query counts stay bounded as rows increase', function () {
    $request = workspaceRequest($this, 1);
    workspacePurchase($this, $request, 1);
    StockBalance::create(['funding_source_id' => $this->workspaceFund->id, 'warehouse_id' => Warehouse::firstOrFail()->id, 'item_id' => $this->workspaceItem->id, 'quantity' => '20']);
    $measure = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        workspacePage($this, $url);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $measure('/app/requests');
    $requestBaseline = $measure('/app/requests');
    $purchaseBaseline = $measure('/app/purchasing');
    for ($number = 2; $number <= 12; $number++) {
        workspacePurchase($this, workspaceRequest($this, $number), $number);
    }
    expect($measure('/app/requests'))->toBeLessThanOrEqual($requestBaseline + 2)
        ->and($measure('/app/purchasing'))->toBeLessThanOrEqual($purchaseBaseline + 2);
});
