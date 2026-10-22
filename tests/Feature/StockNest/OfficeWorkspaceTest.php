<?php

use App\Domain\OfficeWorkflow;
use App\Models\AnnualProjection;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemRequest;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\SupplyReceipt;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\WorkflowVisibility;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['stocknest.office_workflow' => true]);
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $this->actor = User::factory()->create(['department_id' => OfficeWorkflow::departmentId()]);
    $this->actor->assignRole('Super Administrator');
    $this->actor->fundingSources()->sync(FundingSource::pluck('id'));
    $this->actingAs($this->actor);
    $this->fund = FundingSource::where('code', 'GAA')->firstOrFail();
    $this->year = FiscalYear::firstOrFail();
    $this->item = Item::create(['sku' => 'OFFICE-HTTP', 'name' => 'Office paper', 'item_category_id' => ItemCategory::firstOrFail()->id, 'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id, 'standard_cost' => '10.00']);
});

function officeHttpInput($test): array
{
    return ['idempotency_key' => (string) Str::uuid(), 'funding_source_id' => $test->fund->id, 'fiscal_year_id' => $test->year->id,
        'lines' => [['item_id' => $test->item->id, 'quantity' => '10', 'unit_price' => '10.00']]];
}

test('office forms work through HTTP without department or priority selectors', function () {
    $this->post('/app/actions/projection.save', officeHttpInput($this) + ['justification' => 'Supply approved list reference 2026-001'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(AnnualProjection::sole()->status)->toBe('approved')->and(AnnualProjection::sole()->department_id)->toBe(OfficeWorkflow::departmentId());

    $this->post('/app/actions/request.save', officeHttpInput($this) + ['channel' => 'supply', 'required_on' => today()->toDateString(), 'purpose' => 'Monthly office items'])
        ->assertSessionHasNoErrors();
    $request = ItemRequest::sole();
    $this->post('/app/actions/request.submit', ['idempotency_key' => (string) Str::uuid(), 'id' => $request->id])->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('submitted')->and(OfficeWorkflow::allowanceRows($this->actor)[0]['remaining_quantity'])->toBe('0.0000');

    $this->post('/app/actions/supply.receive', ['idempotency_key' => (string) Str::uuid(), 'id' => $request->id, 'warehouse_id' => Warehouse::firstOrFail()->id,
        'delivery_reference' => 'Supply release 01', 'lines' => [['line_id' => $request->lines()->first()->id, 'quantity' => '4']]])->assertSessionHasNoErrors();
    expect(StockBalance::sole()->quantity)->toBe('4.0000')->and($request->fresh()->status)->toBe('partially_received');

    $this->post('/app/actions/stock.consume', ['idempotency_key' => (string) Str::uuid(), 'id' => StockBalance::sole()->id, 'quantity' => '1',
        'recipient' => 'OVPAA staff', 'reason' => 'Office use'])->assertSessionHasNoErrors();
    expect(StockBalance::sole()->quantity)->toBe('3.0000');
});

test('office entry points hide departments and deny redundant approval actions', function () {
    $this->get('/app/projections')->assertOk()->assertInertia(fn (Assert $page) => $page->component('stocknest')
        ->where('officeWorkflow', true)->where('organization', OfficeWorkflow::NAME)
        ->where('createForm.fields', fn ($fields) => ! collect($fields)->contains('name', 'department_id')));
    $this->get('/app/administration')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('page.rows', fn ($rows) => ! collect($rows)->contains('kind', 'Department')));
    $this->get('/app/approvals')->assertNotFound();
    foreach (['request.decide', 'projection.decide', 'allocation.decide', 'purchase.approve', 'count.decide', 'reservation.create', 'transfer.create'] as $operation) {
        $this->post('/app/actions/'.$operation, ['idempotency_key' => (string) Str::uuid(), 'id' => 1, 'decision' => 'approved'])->assertForbidden();
    }
});

test('department inputs cannot change the office identity and office accounts need no department selection', function () {
    $other = Department::create(['code' => 'OTHER', 'name' => 'Other department']);
    $this->post('/app/actions/projection.save', officeHttpInput($this) + ['department_id' => $other->id, 'justification' => 'Externally approved office items'])
        ->assertSessionHasNoErrors();
    expect(AnnualProjection::sole()->department_id)->toBe(OfficeWorkflow::departmentId());
    $this->post('/administration/users', ['name' => 'Office Staff Member', 'email' => 'office.staff@example.test', 'password' => 'Office-Strong123!',
        'role' => 'Office Staff', 'fund_id' => $this->fund->id, 'reason' => 'Office inventory responsibilities', 'idempotency_key' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();
    expect(User::where('email', 'office.staff@example.test')->sole()->department_id)->toBe(OfficeWorkflow::departmentId());
});

test('Supply receipt notifications follow the parent request visibility', function () {
    $this->post('/app/actions/projection.save', officeHttpInput($this) + ['justification' => 'External authority'])->assertSessionHasNoErrors();
    $this->post('/app/actions/request.save', officeHttpInput($this) + ['channel' => 'supply', 'required_on' => today()->toDateString(), 'purpose' => 'Office supplies'])->assertSessionHasNoErrors();
    $request = ItemRequest::sole();
    $this->post('/app/actions/request.submit', ['idempotency_key' => (string) Str::uuid(), 'id' => $request->id])->assertSessionHasNoErrors();
    $this->post('/app/actions/supply.receive', ['idempotency_key' => (string) Str::uuid(), 'id' => $request->id, 'warehouse_id' => Warehouse::firstOrFail()->id,
        'delivery_reference' => 'Supply document', 'lines' => [['line_id' => $request->lines()->first()->id, 'quantity' => '1']]])->assertSessionHasNoErrors();
    $receipt = SupplyReceipt::sole();
    $other = User::factory()->create(['department_id' => OfficeWorkflow::departmentId()]);
    $other->assignRole('Department Requester');
    $other->fundingSources()->sync([$this->fund->id]);
    $payload = ['funding_source_id' => $this->fund->id, 'record_type' => SupplyReceipt::class, 'record_id' => $receipt->id];
    expect(WorkflowVisibility::canSee($this->actor, $payload))->toBeTrue()->and(WorkflowVisibility::canSee($other, $payload))->toBeFalse();
    $other->assignRole('Inventory Custodian');
    expect(WorkflowVisibility::canSee($other, $payload))->toBeTrue();
    $this->actingAs($other)->get('/app/requests')->assertOk()->assertInertia(fn (Assert $page) => $page->where('page.rows.0.id', $request->id));
});

test('fully ordered cash requests stop offering another outside purchase', function () {
    $this->post('/app/actions/allocation.create', ['idempotency_key' => (string) Str::uuid(), 'funding_source_id' => $this->fund->id,
        'fiscal_year_id' => $this->year->id, 'amount' => '1000.00', 'reason' => 'Recorded external cash authority'])->assertSessionHasNoErrors();
    $this->post('/app/actions/request.save', officeHttpInput($this) + ['channel' => 'cash', 'required_on' => today()->toDateString(), 'purpose' => 'Supply has no stock'])->assertSessionHasNoErrors();
    $request = ItemRequest::sole();
    $this->post('/app/actions/request.submit', ['idempotency_key' => (string) Str::uuid(), 'id' => $request->id])->assertSessionHasNoErrors();
    $supplier = Supplier::create(['code' => 'OFFICE-SHOP', 'name' => 'Outside shop']);
    $purchase = ['item_request_id' => $request->id, 'supplier_id' => $supplier->id, 'authorization_reference' => 'External authority 01',
        'lines' => [['line_id' => $request->lines()->first()->id, 'quantity' => '5', 'unit_price' => '10.00']]];
    $this->post('/app/actions/purchase.create', $purchase + ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $this->get('/app/requests')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('page.rows.0.state', 'outside_ordered')
        ->where('page.rows.0.actions', fn ($actions) => collect($actions)->contains('label', 'Record authorized outside purchase')));
    $this->post('/app/actions/purchase.create', $purchase + ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $this->get('/app/requests')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('page.rows.0.actions', fn ($actions) => ! collect($actions)->contains('label', 'Record authorized outside purchase')));
    $other = User::factory()->create(['department_id' => OfficeWorkflow::departmentId()]);
    $other->assignRole('Department Requester');
    $other->fundingSources()->sync([$this->fund->id]);
    expect(WorkflowVisibility::canSee($other, ['funding_source_id' => $this->fund->id, 'record_type' => PurchaseOrder::class, 'record_id' => PurchaseOrder::firstOrFail()->id]))->toBeFalse();
});
