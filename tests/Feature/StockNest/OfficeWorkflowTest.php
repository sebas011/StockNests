<?php

use App\Domain\OfficeWorkflow;
use App\Domain\WorkflowService;
use App\Models\AnnualProjection;
use App\Models\ApprovalDecision;
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
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplyReceipt;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['stocknest.office_workflow' => true]);
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $this->fund = FundingSource::where('code', 'GAA')->firstOrFail();
    $this->otherFund = FundingSource::where('code', 'APPCSE')->firstOrFail();
    $this->year = FiscalYear::firstOrFail();
    $this->warehouse = Warehouse::firstOrFail();
    $this->item = Item::create(['sku' => 'OVPAA-PAPER', 'name' => 'Paper', 'item_category_id' => ItemCategory::firstOrFail()->id, 'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id, 'standard_cost' => '10.00']);
    $this->supplier = Supplier::create(['code' => 'OUTSIDE', 'name' => 'Outside supplier']);
    // Existing accounts may still reference their historical department.
    $oldDepartment = Department::create(['code' => 'OLD', 'name' => 'Historical office']);
    $this->actor = User::factory()->create(['department_id' => $oldDepartment->id]);
    $this->actor->assignRole('Super Administrator');
    $this->actor->fundingSources()->sync([$this->fund->id, $this->otherFund->id]);
    $this->workflow = app(WorkflowService::class);
});

function officeRun($test, string $operation, array $data, ?string $key = null): array
{
    return $test->workflow->execute($test->actor, $operation, $data, $key ?? (string) Str::uuid());
}

function officeScope($test, ?FundingSource $fund = null): array
{
    return ['funding_source_id' => ($fund ?? $test->fund)->id, 'fiscal_year_id' => $test->year->id, 'department_id' => $test->actor->department_id];
}

function officePlan($test, string $quantity = '100', ?FundingSource $fund = null): AnnualProjection
{
    $result = officeRun($test, 'projection.save', officeScope($test, $fund) + ['justification' => 'Encoded from the externally approved annual plan', 'lines' => [['item_id' => $test->item->id, 'quantity' => $quantity, 'unit_price' => '10.00']]]);

    return AnnualProjection::findOrFail($result['id']);
}

function officeRequest($test, string $quantity = '30', string $channel = 'supply', bool $submit = true, ?FundingSource $fund = null): ItemRequest
{
    $result = officeRun($test, 'request.save', officeScope($test, $fund) + ['required_on' => today()->toDateString(), 'purpose' => 'Office requirement', 'channel' => $channel, 'lines' => [['item_id' => $test->item->id, 'quantity' => $quantity, 'unit_price' => '10.00']]]);
    if ($submit) {
        officeRun($test, 'request.submit', ['id' => $result['id']]);
    }

    return ItemRequest::findOrFail($result['id']);
}

function officeReceive($test, ItemRequest $request, string $quantity, ?string $key = null): array
{
    return officeRun($test, 'supply.receive', ['id' => $request->id, 'warehouse_id' => $test->warehouse->id, 'delivery_reference' => 'Supply release 001', 'lines' => [['line_id' => $request->lines()->firstOrFail()->id, 'quantity' => $quantity]]], $key);
}

test('office plans record external approval and always use the academic affairs office', function () {
    $plan = officePlan($this);
    expect($plan->status)->toBe('approved')->and($plan->approved_at)->not->toBeNull()
        ->and((int) $plan->department_id)->toBe(OfficeWorkflow::departmentId())
        ->and($plan->department->name)->toBe(OfficeWorkflow::NAME)
        ->and(ApprovalDecision::count())->toBe(0)
        ->and(FinancialEntry::count())->toBe(0)->and(StockMovement::count())->toBe(0);
});

test('submitted Supply quantities reduce allowance while receipts separately add stock', function () {
    officePlan($this, '100');
    officeRequest($this, '90', submit: false);
    $request = officeRequest($this, '30');
    $row = OfficeWorkflow::allowanceRows($this->actor, $this->fund->id, $this->year->id)[0];
    expect($row['remaining_quantity'])->toBe('70.0000')->and($row['requested_quantity'])->toBe('30.0000')->and($row['received_quantity'])->toBe('0.0000')->and(StockBalance::count())->toBe(0);

    officeReceive($this, $request, '12');
    $row = OfficeWorkflow::allowanceRows($this->actor, $this->fund->id, $this->year->id)[0];
    expect($request->fresh()->status)->toBe('partially_received')->and($request->lines()->first()->received_quantity)->toBe('12.0000')
        ->and($row['remaining_quantity'])->toBe('70.0000')->and($row['received_quantity'])->toBe('12.0000')
        ->and($row['pending_quantity'])->toBe('18.0000')->and(StockBalance::first()->quantity)->toBe('12.0000')
        ->and(FinancialEntry::count())->toBe(0);

    officeReceive($this, $request, '18');
    expect($request->fresh()->status)->toBe('fulfilled')->and(StockBalance::first()->quantity)->toBe('30.0000');
});

test('Supply requests cannot exceed the allowance and cancellation releases unreceived quantities', function () {
    officePlan($this, '1.0001');
    $first = officeRequest($this, '0.7000');
    $next = officeRequest($this, '0.3002', submit: false);
    expect(fn () => officeRun($this, 'request.submit', ['id' => $next->id]))->toThrow(ValidationException::class);
    expect($next->fresh()->status)->toBe('draft')->and($next->fresh()->submitted_at)->toBeNull();
    officeRun($this, 'request.cancel', ['id' => $first->id, 'reason' => 'Supply request withdrawn']);
    officeRun($this, 'request.submit', ['id' => $next->id]);
    expect(OfficeWorkflow::allowanceRows($this->actor)[0]['remaining_quantity'])->toBe('0.6999');
});

test('received Supply requests cannot be canceled or over-received', function () {
    officePlan($this);
    $request = officeRequest($this, '20');
    officeReceive($this, $request, '5');
    expect(fn () => officeRun($this, 'request.cancel', ['id' => $request->id, 'reason' => 'Cancel']))->toThrow(ValidationException::class);
    expect(fn () => officeReceive($this, $request, '16'))->toThrow(ValidationException::class);
    expect(SupplyReceipt::count())->toBe(1)->and(StockBalance::first()->quantity)->toBe('5.0000')->and($request->lines()->first()->received_quantity)->toBe('5.0000');
});

test('the shared office allowance includes requests submitted by other staff', function () {
    officePlan($this, '100');
    officeRequest($this, '70');
    $requester = User::factory()->create(['department_id' => $this->actor->department_id]);
    $requester->assignRole('Department Requester');
    $requester->fundingSources()->sync([$this->fund->id]);
    $record = $this->workflow->execute($requester, 'request.save', officeScope($this) + ['channel' => 'supply', 'required_on' => today()->toDateString(), 'purpose' => 'Another staff member requirement', 'lines' => [['item_id' => $this->item->id, 'quantity' => '40', 'unit_price' => '10.00']]], (string) Str::uuid());
    expect(fn () => $this->workflow->execute($requester, 'request.submit', ['id' => $record['id']], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(ItemRequest::findOrFail($record['id'])->status)->toBe('draft')
        ->and(OfficeWorkflow::allowanceRows($requester)[0]['remaining_quantity'])->toBe('30.0000');
});

test('cash requests and outside purchases never consume the Supply allowance', function () {
    officePlan($this, '10');
    $supply = officeRequest($this, '3');
    $cash = officeRequest($this, '50', 'cash');
    officeRun($this, 'allocation.create', officeScope($this) + ['amount' => '1000.00', 'reason' => 'Externally authorized office budget']);
    $input = ['item_request_id' => $cash->id, 'supplier_id' => $this->supplier->id, 'lines' => [['line_id' => $cash->lines()->first()->id, 'quantity' => '50', 'unit_price' => '10.00']]];
    expect(fn () => officeRun($this, 'purchase.create', $input))->toThrow(ValidationException::class);
    $order = officeRun($this, 'purchase.create', $input + ['authorization_reference' => 'Cash authority 2026-001']);
    $order = PurchaseOrder::findOrFail($order['id']);
    expect($cash->status)->toBe('approved')->and($order->status)->toBe('ordered')->and($order->committed_amount)->toBe('500.00')
        ->and($order->authorization_reference)->toBe('Cash authority 2026-001')->and(ApprovalDecision::count())->toBe(0);
    $delivery = ['id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'delivery_reference' => 'Outside receipt', 'lines' => [['line_id' => $order->lines()->first()->id, 'quantity' => '25']]];
    $deliveryKey = (string) Str::uuid();
    officeRun($this, 'receipt.create', $delivery, $deliveryKey);
    officeRun($this, 'receipt.create', $delivery, $deliveryKey);
    expect($cash->fresh()->status)->toBe('partially_fulfilled')->and($cash->lines()->first()->received_quantity)->toBe('25.0000');
    officeRun($this, 'receipt.create', $delivery);
    expect(OfficeWorkflow::allowanceRows($this->actor)[0]['remaining_quantity'])->toBe('7.0000')->and(StockBalance::first()->quantity)->toBe('50.0000')
        ->and($cash->fresh()->status)->toBe('fulfilled')->and($cash->lines()->first()->received_quantity)->toBe('50.0000');
    expect(fn () => officeRun($this, 'purchase.create', ['item_request_id' => $supply->id, 'supplier_id' => $this->supplier->id, 'authorization_reference' => 'Authority', 'lines' => []]))->toThrow(ValidationException::class);
});

test('cash needs may be recorded without a Supply plan but Supply submission requires one', function () {
    $cash = officeRequest($this, '2', 'cash');
    expect($cash->status)->toBe('approved')->and($cash->is_unplanned)->toBeTrue();
    $supply = officeRequest($this, '2', submit: false);
    expect(fn () => officeRun($this, 'request.submit', ['id' => $supply->id]))->toThrow(ValidationException::class);
});

test('approved amendments cannot erase quantities already submitted to Supply', function () {
    $original = officePlan($this);
    officeRequest($this, '30');
    $input = officeScope($this) + ['original_projection_id' => $original->id, 'justification' => 'External revised annual approval', 'lines' => [['item_id' => $this->item->id, 'quantity' => '29', 'unit_price' => '10.00']]];
    expect(fn () => officeRun($this, 'projection.save', $input))->toThrow(ValidationException::class);
    $input['lines'][0]['quantity'] = '60';
    $revision = officeRun($this, 'projection.save', $input);
    expect(AnnualProjection::find($revision['id'])->revision)->toBe(2)->and($original->lines()->first()->quantity)->toBe('100.0000')
        ->and(OfficeWorkflow::allowanceRows($this->actor)[0]['remaining_quantity'])->toBe('30.0000');
});

test('GAA and APPCSE maintain independent approved item allowances', function () {
    officePlan($this, '100');
    officePlan($this, '40', $this->otherFund);
    officeRequest($this, '30');
    officeRequest($this, '10', fund: $this->otherFund);
    $rows = collect(OfficeWorkflow::allowanceRows($this->actor))->keyBy('fund');
    expect($rows['GAA']['remaining_quantity'])->toBe('70.0000')->and($rows['APPCSE']['remaining_quantity'])->toBe('30.0000');
});

test('office use subtracts stock without changing annual Supply request quantities', function () {
    officePlan($this);
    $request = officeRequest($this, '20');
    officeReceive($this, $request, '20');
    $balance = StockBalance::firstOrFail();
    $input = ['id' => $balance->id, 'quantity' => '7', 'recipient' => 'OVPAA staff', 'reason' => 'Monthly office use'];
    $key = (string) Str::uuid();
    officeRun($this, 'stock.consume', $input, $key);
    officeRun($this, 'stock.consume', $input, $key);
    expect($balance->fresh()->quantity)->toBe('13.0000')->and(StockMovement::where('kind', 'consumption')->count())->toBe(1)
        ->and(OfficeWorkflow::allowanceRows($this->actor)[0]['remaining_quantity'])->toBe('80.0000');
    $input['quantity'] = '14';
    expect(fn () => officeRun($this, 'stock.consume', $input))->toThrow(ValidationException::class);
});

test('Supply submission and receiving retries apply once', function () {
    officePlan($this);
    $request = officeRequest($this, '10', submit: false);
    $key = (string) Str::uuid();
    officeRun($this, 'request.submit', ['id' => $request->id], $key);
    officeRun($this, 'request.submit', ['id' => $request->id], $key);
    $receiveKey = (string) Str::uuid();
    officeReceive($this, $request, '4', $receiveKey);
    officeReceive($this, $request, '4', $receiveKey);
    expect(SupplyReceipt::count())->toBe(1)->and(StockBalance::first()->quantity)->toBe('4.0000')
        ->and(OfficeWorkflow::allowanceRows($this->actor)[0]['requested_quantity'])->toBe('10.0000');
});

test('office counts can be finalized and reconciled without an internal approver', function () {
    $result = officeRun($this, 'count.create', officeScope($this) + ['warehouse_id' => $this->warehouse->id, 'counter_id' => $this->actor->id, 'count_month' => now()->startOfMonth()->toDateString()]);
    $count = InventoryCount::findOrFail($result['id']);
    officeRun($this, 'count.encode', ['id' => $count->id, 'lines' => [['line_id' => $count->lines()->first()->id, 'physical_quantity' => '3', 'variance_reason' => 'Verified opening stock']]]);
    officeRun($this, 'count.submit', ['id' => $count->id]);
    expect($count->fresh()->status)->toBe('approved')->and(StockBalance::count())->toBe(0);
    officeRun($this, 'count.reconcile', ['id' => $count->id]);
    expect($count->fresh()->status)->toBe('reconciled')->and(StockBalance::first()->quantity)->toBe('3.0000')->and(ApprovalDecision::count())->toBe(0);
});

test('office workflow rejects redundant approvals and additional departments', function () {
    foreach (['projection.submit', 'projection.decide', 'request.decide', 'allocation.decide', 'purchase.approve', 'count.decide'] as $operation) {
        expect(fn () => officeRun($this, $operation, ['id' => 1, 'decision' => 'approved']))->toThrow(ValidationException::class);
    }
    expect(fn () => officeRun($this, 'master.save', ['kind' => 'department', 'code' => 'EXTRA', 'name' => 'Extra department']))->toThrow(ValidationException::class);
});

test('office entry retains fund permissions and cash budget limits', function () {
    officeRun($this, 'allocation.create', officeScope($this) + ['amount' => '10.00', 'reason' => 'External budget authority']);
    $cash = officeRequest($this, '2', 'cash');
    expect(fn () => officeRun($this, 'purchase.create', ['item_request_id' => $cash->id, 'supplier_id' => $this->supplier->id, 'authorization_reference' => 'Cash authority', 'lines' => [['line_id' => $cash->lines()->first()->id, 'quantity' => '2', 'unit_price' => '10.00']]]))->toThrow(ValidationException::class);
    expect(PurchaseOrder::count())->toBe(0)->and(BudgetAllocation::first()->authorized_amount)->toBe('10.00')->and(FinancialEntry::count())->toBe(0);
    $this->actor->fundingSources()->detach($this->otherFund->id);
    expect(fn () => officePlan($this, fund: $this->otherFund))->toThrow(AuthorizationException::class);
});
