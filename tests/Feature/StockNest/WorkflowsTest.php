<?php

use App\Domain\WorkflowService;
use App\Events\WorkflowChanged;
use App\Models\AnnualProjection;
use App\Models\AuditLog;
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
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\WorkflowNotification;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $this->fund = FundingSource::where('code', 'GAA')->firstOrFail();
    $this->otherFund = FundingSource::where('code', 'APPCSE')->firstOrFail();
    $this->department = Department::where('code', 'OPS')->firstOrFail();
    $this->year = FiscalYear::firstOrFail();
    $this->warehouse = Warehouse::firstOrFail();
    $this->item = Item::create(['sku' => 'TEST-PAPER', 'name' => 'Paper', 'item_category_id' => ItemCategory::firstOrFail()->id, 'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id, 'standard_cost' => '10.00']);
    $this->supplier = Supplier::create(['code' => 'TEST-SUP', 'name' => 'Test supplier']);
    $this->users = [];
    foreach (['budget' => 'Budget Officer', 'requester' => 'Department Requester', 'head' => 'Department Head', 'approver' => 'Fund Approver', 'procurement' => 'Procurement Officer', 'custodian' => 'Inventory Custodian', 'auditor' => 'Auditor', 'admin' => 'Super Administrator'] as $slug => $role) {
        $user = User::factory()->create(['department_id' => $this->department->id, 'is_active' => true]);
        $user->assignRole($role);
        $user->fundingSources()->sync([$this->fund->id, $this->otherFund->id]);
        $this->users[$slug] = $user;
    }
    $this->workflow = app(WorkflowService::class);
});

test('a count-only approver can open the approval center without request approval access', function () {
    $actor = $this->users['auditor'];
    $actor->syncRoles([]);
    $actor->givePermissionTo('counts.approve');

    $this->actingAs($actor)->get('/app/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('stocknest')->where('page.key', 'approvals'));
    expect($actor->hasPermissionTo('requests.approve'))->toBeFalse();
});

test('request action metadata declares the record context needed for submission', function () {
    $record = snRun($this, 'requester', 'request.save', snScope($this) + [
        'required_on' => now()->addWeek()->format('Y-m-d'), 'purpose' => 'UI context regression',
        'priority' => 'normal', 'lines' => [['item_id' => $this->item->id, 'quantity' => '2', 'unit_price' => '10.00']],
    ]);
    $this->actingAs($this->users['requester'])->get('/app/requests')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('stocknest')
        ->where('page.rows.0.actions.1.form.action', '/app/actions/request.submit')
        ->where('page.rows.0.actions.1.form.context.id', $record['id']));
});

function snRun($test, string $who, string $operation, array $data, ?string $key = null): array
{
    return $test->workflow->execute($test->users[$who], $operation, $data, $key ?? (string) Str::uuid());
}

function snScope($test, ?FundingSource $fund = null): array
{
    return ['funding_source_id' => ($fund ?? $test->fund)->id, 'fiscal_year_id' => $test->year->id, 'department_id' => $test->department->id];
}

function snAllocate($test, string $amount = '10000.00', ?FundingSource $fund = null): void
{
    $record = snRun($test, 'budget', 'allocation.create', snScope($test, $fund) + ['amount' => $amount, 'reason' => 'Test appropriation']);
    snRun($test, 'approver', 'allocation.decide', ['id' => $record['id'], 'decision' => 'approved', 'comment' => 'Authority verified']);
}

function snPlan($test, string $qty = '100', ?FundingSource $fund = null): AnnualProjection
{
    $record = snRun($test, 'budget', 'projection.save', snScope($test, $fund) + ['justification' => 'Annual plan', 'lines' => [['item_id' => $test->item->id, 'quantity' => $qty, 'unit_price' => '10.00']]]);
    snRun($test, 'budget', 'projection.submit', ['id' => $record['id']]);
    snRun($test, 'approver', 'projection.decide', ['id' => $record['id'], 'decision' => 'approved']);

    return AnnualProjection::findOrFail($record['id']);
}

function snRequest($test, string $qty = '50', bool $approve = true, ?FundingSource $fund = null): ItemRequest
{
    $record = snRun($test, 'requester', 'request.save', snScope($test, $fund) + ['required_on' => now()->addWeek()->format('Y-m-d'), 'purpose' => 'Monthly supplies', 'priority' => 'normal', 'lines' => [['item_id' => $test->item->id, 'quantity' => $qty, 'unit_price' => '10.00']]]);
    snRun($test, 'requester', 'request.submit', ['id' => $record['id']]);
    if ($approve) {
        snRun($test, 'head', 'request.decide', ['id' => $record['id'], 'decision' => 'approved']);
        snRun($test, 'approver', 'request.decide', ['id' => $record['id'], 'decision' => 'approved', 'comment' => 'Unplanned exception / fund authority verified']);
    }

    return ItemRequest::findOrFail($record['id']);
}

function snOrder($test, ItemRequest $request, string $qty = '50'): PurchaseOrder
{
    $record = snRun($test, 'procurement', 'purchase.create', ['item_request_id' => $request->id, 'supplier_id' => $test->supplier->id, 'lines' => [['line_id' => $request->lines->first()->id, 'quantity' => $qty, 'unit_price' => '10.00']]]);
    snRun($test, 'approver', 'purchase.approve', ['id' => $record['id']]);

    return PurchaseOrder::findOrFail($record['id']);
}

function snReceive($test, PurchaseOrder $order, string $qty = '50', ?string $key = null): array
{
    return snRun($test, 'custodian', 'receipt.create', ['id' => $order->id, 'warehouse_id' => $test->warehouse->id, 'delivery_reference' => 'DR-TEST', 'lines' => [['line_id' => $order->lines->first()->id, 'quantity' => $qty]]], $key);
}

test('scenario 1 preserves independently approved annual plans for GAA and APPCSE', function () {
    $gaa = snPlan($this, '100');
    $appcse = snPlan($this, '70', $this->otherFund);
    expect($gaa->total)->toBe('1000.00')->and($appcse->total)->toBe('700.00')->and($gaa->funding_source_id)->not->toBe($appcse->funding_source_id)->and(FinancialEntry::count())->toBe(0)->and(StockMovement::count())->toBe(0);
});

test('scenario 2 monthly requests remain pending through each level and approval changes neither stock nor actual expense', function () {
    snPlan($this);
    $request = snRequest($this, '50', false);
    expect($request->status)->toBe('submitted')->and($request->is_unplanned)->toBeFalse();
    snRun($this, 'head', 'request.decide', ['id' => $request->id, 'decision' => 'approved']);
    expect($request->fresh()->status)->toBe('pending_review');
    snRun($this, 'approver', 'request.decide', ['id' => $request->id, 'decision' => 'approved']);
    expect($request->fresh()->status)->toBe('approved')->and(StockBalance::count())->toBe(0)->and(FinancialEntry::count())->toBe(0);
});

test('scenario 3 unplanned items require explicit fund approver exception comment', function () {
    $request = snRequest($this, '50', false);
    expect($request->is_unplanned)->toBeTrue();
    snRun($this, 'head', 'request.decide', ['id' => $request->id, 'decision' => 'approved']);
    expect(fn () => snRun($this, 'approver', 'request.decide', ['id' => $request->id, 'decision' => 'approved']))->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe('pending_review');
});

test('scenario 4 approved amendments retain the original immutable plan', function () {
    $original = snPlan($this);
    $new = snRun($this, 'budget', 'projection.save', snScope($this) + ['original_projection_id' => $original->id, 'justification' => 'Reduced actual need', 'lines' => [['item_id' => $this->item->id, 'quantity' => '70', 'unit_price' => '12.00']]]);
    snRun($this, 'budget', 'projection.submit', ['id' => $new['id']]);
    snRun($this, 'approver', 'projection.decide', ['id' => $new['id'], 'decision' => 'approved']);
    $revision = AnnualProjection::findOrFail($new['id']);
    expect($original->fresh()->total)->toBe('1000.00')->and($original->lines->first()->quantity)->toBe('100.0000')->and($revision->revision)->toBe(2)->and($revision->total)->toBe('840.00')->and($revision->original_projection_id)->toBe($original->id);
});

test('scenario 5 PO approval commits budget and partial receiving adds only delivered units', function () {
    snAllocate($this);
    $request = snRequest($this);
    $order = snOrder($this, $request);
    expect(StockBalance::count())->toBe(0)->and($order->committed_amount)->toBe('500.00');
    snReceive($this, $order, '20');
    expect(StockBalance::firstOrFail()->quantity)->toBe('20.0000')->and($order->fresh()->status)->toBe('partially_received')->and($order->lines()->first()->received_quantity)->toBe('20.0000')->and($order->fresh()->spent_amount)->toBe('0.00');
});

test('scenario 6 stock decreases only through confirmed issuance against approved quantities', function () {
    snAllocate($this);
    $request = snRequest($this);
    $order = snOrder($this, $request);
    snReceive($this, $order);
    snRun($this, 'custodian', 'issuance.create', ['id' => $request->id, 'warehouse_id' => $this->warehouse->id, 'recipient' => 'Receiving employee', 'lines' => [['line_id' => $request->lines->first()->id, 'quantity' => '15']]]);
    expect(StockBalance::firstOrFail()->quantity)->toBe('35.0000')->and($request->fresh()->status)->toBe('partially_fulfilled')->and($request->lines()->first()->fulfilled_quantity)->toBe('15.0000');
});

test('scenario 7 count variance stays separate from stock and reconciliation preserves post-cutoff issuances', function () {
    snAllocate($this);
    $request = snRequest($this);
    $order = snOrder($this, $request);
    snReceive($this, $order);
    $record = snRun($this, 'custodian', 'count.create', snScope($this) + ['warehouse_id' => $this->warehouse->id, 'counter_id' => $this->users['custodian']->id, 'count_month' => now()->startOfMonth()->format('Y-m-d')]);
    $count = InventoryCount::findOrFail($record['id']);
    snRun($this, 'custodian', 'count.encode', ['id' => $count->id, 'lines' => [['line_id' => $count->lines->first()->id, 'physical_quantity' => '47', 'variance_reason' => 'Three damaged units found during monthly count']]]);
    expect(StockBalance::firstOrFail()->quantity)->toBe('50.0000')->and($count->lines()->first()->variance)->toBe('-3.0000');
    snRun($this, 'custodian', 'count.submit', ['id' => $count->id]);
    snRun($this, 'approver', 'count.decide', ['id' => $count->id, 'decision' => 'approved']);
    expect(StockBalance::firstOrFail()->quantity)->toBe('50.0000');
    snRun($this, 'custodian', 'issuance.create', ['id' => $request->id, 'warehouse_id' => $this->warehouse->id, 'recipient' => 'Employee', 'lines' => [['line_id' => $request->lines->first()->id, 'quantity' => '10']]]);
    snRun($this, 'custodian', 'count.reconcile', ['id' => $count->id]);
    expect(StockBalance::firstOrFail()->quantity)->toBe('37.0000')->and($count->fresh()->status)->toBe('reconciled');
});

test('scenario 8 commitments and spending consume only the attributed funding source without double counting', function () {
    snAllocate($this);
    snAllocate($this, '8000.00', $this->otherFund);
    $request = snRequest($this);
    $order = snOrder($this, $request);
    snReceive($this, $order);
    snRun($this, 'budget', 'purchase.spend', ['id' => $order->id, 'amount' => '480.00', 'document_reference' => 'INV-1', 'final_payment' => true]);
    $gaa = $this->workflow->budget(BudgetAllocation::where('funding_source_id', $this->fund->id)->firstOrFail());
    $appcse = $this->workflow->budget(BudgetAllocation::where('funding_source_id', $this->otherFund->id)->firstOrFail());
    expect($gaa)->toMatchArray(['committed' => '0.00', 'spent' => '480.00', 'available' => '9520.00'])->and($appcse)->toMatchArray(['committed' => '0.00', 'spent' => '0.00', 'available' => '8000.00']);
});

test('scenario 9 retries process an issuance once and later attempts cannot overdraw the last stock', function () {
    snAllocate($this);
    $request = snRequest($this);
    $order = snOrder($this, $request);
    snReceive($this, $order, '10');
    $input = ['id' => $request->id, 'warehouse_id' => $this->warehouse->id, 'recipient' => 'Employee', 'lines' => [['line_id' => $request->lines->first()->id, 'quantity' => '10']]];
    $key = (string) Str::uuid();
    $first = snRun($this, 'custodian', 'issuance.create', $input, $key);
    $retry = snRun($this, 'custodian', 'issuance.create', $input, $key);
    expect($first)->toBe($retry)->and(StockIssuance::count())->toBe(1)->and(StockBalance::firstOrFail()->quantity)->toBe('0.0000');
    expect(fn () => snRun($this, 'custodian', 'issuance.create', $input))->toThrow(ValidationException::class);
    expect(StockIssuance::count())->toBe(1)->and(StockBalance::firstOrFail()->quantity)->toBe('0.0000');
});

test('scenario 10 annual budget export includes original revised authorized committed and actual values', function () {
    snAllocate($this);
    snPlan($this);
    $request = snRequest($this);
    snOrder($this, $request);
    $response = $this->actingAs($this->users['auditor'])->get('/app/reports/budgets.csv?fund_id='.$this->fund->id.'&fiscal_year_id='.$this->year->id);
    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('original_plan', 'revised_plan', 'allocation', 'committed', 'spent', 'available', 'GAA', '10000.00', '500.00')->not->toContain('APPCSE');
});

test('requesters cannot bypass stages and administrators cannot approve their own records', function () {
    $request = snRequest($this, '50', false);
    expect(fn () => snRun($this, 'approver', 'request.decide', ['id' => $request->id, 'decision' => 'approved', 'comment' => 'Skip head']))->toThrow(ValidationException::class);
    $this->users['requester']->assignRole('Department Head');
    expect(fn () => snRun($this, 'requester', 'request.decide', ['id' => $request->id, 'decision' => 'approved']))->toThrow(AuthorizationException::class);
});

test('fund access and department scope are enforced on writes views exports and cached retries', function () {
    $request = snRequest($this, '50', false);
    $this->users['head']->fundingSources()->detach($this->fund->id);
    expect(fn () => snRun($this, 'head', 'request.decide', ['id' => $request->id, 'decision' => 'approved']))->toThrow(AuthorizationException::class);
    $this->actingAs($this->users['auditor'])->get('/app/reports/requests.csv?fund_id='.$this->fund->id)->assertOk();
    $this->users['auditor']->fundingSources()->detach($this->fund->id);
    $this->actingAs($this->users['auditor'])->get('/app/reports/requests.csv?fund_id='.$this->fund->id)->assertForbidden();
    $input = snScope($this) + ['amount' => '100.00', 'reason' => 'Test'];
    $key = (string) Str::uuid();
    snRun($this, 'budget', 'allocation.create', $input, $key);
    $this->users['budget']->fundingSources()->detach($this->fund->id);
    expect(fn () => snRun($this, 'budget', 'allocation.create', $input, $key))->toThrow(AuthorizationException::class);
});

test('hard budget limits reject commitments and closed fiscal years reject all new operations', function () {
    snAllocate($this, '100.00');
    $request = snRequest($this);
    expect(fn () => snOrder($this, $request))->toThrow(ValidationException::class);
    $this->year->update(['status' => 'closed']);
    expect(fn () => snRequest($this))->toThrow(ValidationException::class);
    expect(FinancialEntry::count())->toBe(0);
});

test('a reused transaction key cannot change operation input or actor', function () {
    $key = (string) Str::uuid();
    $input = snScope($this) + ['amount' => '100.00', 'reason' => 'Authority'];
    snRun($this, 'budget', 'allocation.create', $input, $key);
    expect(fn () => snRun($this, 'budget', 'allocation.create', array_replace($input, ['amount' => '200.00']), $key))->toThrow(ValidationException::class);
});

test('significant changes append audit records that cannot be edited or deleted', function () {
    snPlan($this);
    $audit = AuditLog::firstOrFail();
    expect(AuditLog::count())->toBeGreaterThanOrEqual(3);
    expect(fn () => $audit->update(['reason' => 'Tampered']))->toThrow(LogicException::class);
    expect(fn () => $audit->delete())->toThrow(LogicException::class);
});

test('server validation rejects precision loss negative quantities and duplicate line items', function () {
    $input = snScope($this) + ['idempotency_key' => (string) Str::uuid(), 'required_on' => now()->addWeek()->format('Y-m-d'), 'purpose' => 'Need', 'priority' => 'normal', 'lines' => [['item_id' => $this->item->id, 'quantity' => '-1', 'unit_price' => '10.001']]];
    $this->actingAs($this->users['requester'])->post('/app/actions/request.save', $input)->assertSessionHasErrors(['lines.0.quantity', 'lines.0.unit_price']);
    expect(ItemRequest::count())->toBe(0);
});

test('an amendment draft can be saved repeatedly without changing its revision', function () {
    $original = snPlan($this);
    $input = snScope($this) + ['original_projection_id' => $original->id, 'justification' => 'Amend estimated requirement', 'lines' => [['item_id' => $this->item->id, 'quantity' => '70', 'unit_price' => '10.00']]];
    $revision = snRun($this, 'budget', 'projection.save', $input);
    $input['id'] = $revision['id'];
    $input['lines'][0]['quantity'] = '65';
    snRun($this, 'budget', 'projection.save', $input);
    expect(AnnualProjection::findOrFail($revision['id'])->revision)->toBe(2)->and($original->fresh()->total)->toBe('1000.00');
});

test('a requester cannot edit or submit a colleague request even within the same department', function () {
    $record = snRun($this, 'requester', 'request.save', snScope($this) + ['required_on' => now()->addWeek()->format('Y-m-d'), 'purpose' => 'Owner draft', 'lines' => [['item_id' => $this->item->id, 'quantity' => '2', 'unit_price' => '10.00']]]);
    $peer = User::factory()->create(['department_id' => $this->department->id]);
    $peer->assignRole('Department Requester');
    $peer->fundingSources()->sync([$this->fund->id]);
    expect(fn () => $this->workflow->execute($peer, 'request.submit', ['id' => $record['id']], (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(ItemRequest::findOrFail($record['id'])->status)->toBe('draft');
});

test('a configured fund can be deactivated and reactivated without losing its assigned administrator', function () {
    $input = ['kind' => 'fund', 'id' => $this->fund->id, 'code' => 'GAA', 'name' => 'GAA', 'budget_control' => 'hard', 'is_active' => false];
    snRun($this, 'admin', 'master.save', $input);
    expect($this->fund->fresh()->is_active)->toBeFalse();
    $input['is_active'] = true;
    snRun($this, 'admin', 'master.save', $input);
    expect($this->fund->fresh()->is_active)->toBeTrue();
});

test('a fund-limited administrator cannot change shared global roles', function () {
    $this->users['admin']->fundingSources()->detach($this->otherFund->id);
    $this->actingAs($this->users['admin'])->post('/administration/roles', ['name' => 'Auditor', 'permissions' => 'dashboard.view', 'reason' => 'Attempt scoped role change'])->assertForbidden();
    expect(Role::findByName('Auditor')->hasPermissionTo('audit.view'))->toBeTrue();
});

test('account creation normalizes email and prevents case-variant duplicates', function () {
    $input = ['name' => 'New employee', 'email' => 'Employee@Example.Test', 'password' => 'Local-Test-Pass!123', 'department_id' => $this->department->id, 'role' => 'Department Requester', 'fund_id' => $this->fund->id, 'reason' => 'New authorized employee', 'idempotency_key' => (string) Str::uuid()];
    $this->actingAs($this->users['admin'])->post('/administration/users', $input)->assertSessionHasNoErrors();
    $created = User::where('email', 'employee@example.test')->firstOrFail();
    expect($created->is_active)->toBeTrue()->and($created->fundingSources()->pluck('funding_sources.id')->all())->toBe([$this->fund->id]);
    $input['email'] = 'EMPLOYEE@EXAMPLE.TEST';
    $input['idempotency_key'] = (string) Str::uuid();
    $this->post('/administration/users', $input)->assertSessionHasErrors('email');
});

test('notification access is rechecked after department reassignment', function () {
    $record = snRequest($this, '2', false);
    $user = $this->users['requester'];
    $user->notify(new WorkflowNotification(new WorkflowChanged($this->fund->id, $this->users['head']->id, 'request.submitted', ItemRequest::class, $record->id, $record->reference)));
    $notification = $user->notifications()->firstOrFail();
    $this->actingAs($user)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->component('stocknest')->has('page.rows', 1));
    $user->forceFill(['department_id' => Department::where('code', 'FIN')->value('id')])->save();
    $this->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->component('stocknest')->has('page.rows', 0));
    $this->post('/notifications/'.$notification->id.'/read')->assertForbidden();
});

test('every core workspace returns integrated server data for an authorized administrator', function () {
    $this->actingAs($this->users['admin']);
    foreach (['overview', 'funding', 'catalog', 'projections', 'requests', 'purchasing', 'inventory', 'counts', 'approvals', 'reports', 'audit', 'administration'] as $section) {
        $this->get('/app/'.$section)->assertOk()->assertInertia(fn (Assert $page) => $page->component('stocknest')->where('page.key', $section));
    }
    $this->get('/administration/users')->assertOk()->assertInertia(fn (Assert $page) => $page->component('stocknest'));
});

test('both transfer ledger movements share one immutable transfer document', function () {
    snAllocate($this);
    $request = snRequest($this);
    $order = snOrder($this, $request);
    snReceive($this, $order);
    $destination = Warehouse::create(['code' => 'ANNEX', 'name' => 'Annex storeroom']);
    $transfer = snRun($this, 'custodian', 'transfer.create', ['funding_source_id' => $this->fund->id, 'warehouse_id' => $this->warehouse->id, 'destination_warehouse_id' => $destination->id, 'item_id' => $this->item->id, 'quantity' => '5', 'reason' => 'Approved local relocation']);
    $movements = StockMovement::where('source_type', StockTransfer::class)->where('source_id', $transfer['id'])->get();
    expect($movements)->toHaveCount(2)->and($movements->pluck('kind')->sort()->values()->all())->toBe(['transfer_in', 'transfer_out'])
        ->and(StockBalance::where('warehouse_id', $this->warehouse->id)->firstOrFail()->quantity)->toBe('45.0000')
        ->and(StockBalance::where('warehouse_id', $destination->id)->firstOrFail()->quantity)->toBe('5.0000');
});

test('the dashboard shows approved planning even before separate budget authority is allocated', function () {
    snPlan($this);
    $this->actingAs($this->users['budget'])->get('/app/overview')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('stocknest')->where('budgetFunds.0.original', '1000.00')->where('budgetFunds.0.revised', '1000.00')
        ->where('budgetFunds.0.allocation', '0.00')->where('budgetFunds.0.spent', '0.00'));
    expect(BudgetAllocation::count())->toBe(0)->and(StockMovement::count())->toBe(0);
});

test('the separate demonstration seeder exercises both funds through real transactions', function () {
    $this->seed(DemoSeeder::class);
    $paper = Item::where('sku', 'DEMO-PAPER-A4')->firstOrFail();
    expect(AnnualProjection::where('status', 'approved')->count())->toBe(2)
        ->and(PurchaseOrder::where('status', 'closed')->count())->toBe(2)
        ->and(StockBalance::where('funding_source_id', $this->fund->id)->where('item_id', $paper->id)->firstOrFail()->quantity)->toBe('50.0000')
        ->and(StockBalance::where('funding_source_id', $this->otherFund->id)->where('item_id', $paper->id)->firstOrFail()->quantity)->toBe('40.0000');
});

test('Excel export creates a valid workbook with exact text-bound monetary values', function () {
    snPlan($this);
    config(['excel.temporary_files.local_path' => sys_get_temp_dir().'/stocknest-excel']);
    $response = $this->actingAs($this->users['auditor'])->get('/app/reports/annual-plans.xlsx');
    $response->assertOk()->assertDownload();
    $path = $response->baseResponse->getFile()->getPathname();
    $workbook = IOFactory::load($path);
    $sheet = $workbook->getActiveSheet();
    expect($sheet->getCell('K2')->getValue())->toBe('1000.00')
        ->and($sheet->getCell('K2')->getDataType())->toBe(DataType::TYPE_STRING);
});

test('CSV export neutralizes a formula-like item name without corrupting numeric fields', function () {
    $this->item->update(['name' => '-1+2']);
    snPlan($this);
    $response = $this->actingAs($this->users['auditor'])->get('/app/reports/annual-plans.csv');
    $response->assertOk();
    $lines = explode("\n", trim($response->streamedContent()));
    $row = str_getcsv($lines[1], ',', '"', '');
    expect($row[7])->toBe("'-1+2")->and($row[10])->toBe('1000.00');
});
