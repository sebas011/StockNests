<?php

use App\Domain\OfficeMonthlyStatistics;
use App\Domain\OfficeWorkflow;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemRequest;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplyReceipt;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['stocknest.office_workflow' => true]);
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $this->fund = FundingSource::where('code', 'GAA')->firstOrFail();
    $this->otherFund = FundingSource::where('code', 'APPCSE')->firstOrFail();
    $this->year = FiscalYear::firstOrFail();
    $this->warehouse = Warehouse::firstOrFail();
    $this->item = Item::create(['sku' => 'MONTHLY-PAPER', 'name' => 'Monthly paper', 'item_category_id' => ItemCategory::firstOrFail()->id, 'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id, 'standard_cost' => '10.00']);
    $this->supplier = Supplier::create(['code' => 'MONTHLY-SUPPLIER', 'name' => 'Outside supplier']);
    $this->actor = User::factory()->create(['department_id' => OfficeWorkflow::departmentId()]);
    $this->actor->assignRole('Super Administrator');
    $this->actor->fundingSources()->sync([$this->fund->id]);
    $this->actingAs($this->actor);
});

function monthlyRequest($test, string $quantity, string $submitted, string $channel = 'supply', string $status = 'submitted', ?FundingSource $fund = null, ?int $departmentId = null, ?int $yearId = null): ItemRequest
{
    $request = ItemRequest::create([
        'reference' => 'MONTHLY-'.Str::uuid(), 'funding_source_id' => ($fund ?? $test->fund)->id,
        'department_id' => $departmentId ?? OfficeWorkflow::departmentId(), 'fiscal_year_id' => $yearId ?? $test->year->id,
        'created_by' => $test->actor->id, 'required_on' => '2026-01-01', 'purpose' => 'Monthly reporting fixture',
        'channel' => $channel, 'status' => $status, 'estimated_total' => '0.00',
        'submitted_at' => $status === 'draft' ? null : $submitted,
        // Creation date deliberately differs from submission date.
        'created_at' => '2025-12-15 12:00:00',
    ]);
    $request->lines()->create(['item_id' => $test->item->id, 'quantity' => $quantity, 'unit_price' => '0.00']);

    return $request;
}

function monthlySupplyReceipt($test, ItemRequest $request, string $quantity, string $date): void
{
    $receipt = SupplyReceipt::create([
        'reference' => 'SUPPLY-'.Str::uuid(), 'item_request_id' => $request->id, 'funding_source_id' => $request->funding_source_id,
        'warehouse_id' => $test->warehouse->id, 'received_by' => $test->actor->id, 'delivery_reference' => 'Supply release', 'created_at' => $date,
    ]);
    $receipt->lines()->create(['item_request_line_id' => $request->lines()->firstOrFail()->id, 'item_id' => $test->item->id, 'quantity' => $quantity]);
}

function monthlyOutsideReceipt($test, ItemRequest $request, string $quantity, string $date): void
{
    $order = PurchaseOrder::create([
        'reference' => 'OUTSIDE-'.Str::uuid(), 'item_request_id' => $request->id, 'supplier_id' => $test->supplier->id,
        'funding_source_id' => $request->funding_source_id, 'fiscal_year_id' => $request->fiscal_year_id,
        'department_id' => $request->department_id, 'created_by' => $test->actor->id, 'status' => 'approved', 'total' => '0.00',
    ]);
    $line = $order->lines()->create(['item_request_line_id' => $request->lines()->firstOrFail()->id, 'item_id' => $test->item->id, 'quantity' => $quantity, 'received_quantity' => $quantity, 'unit_price' => '0.00', 'total' => '0.00']);
    $receipt = GoodsReceipt::create([
        'reference' => 'RECEIPT-'.Str::uuid(), 'purchase_order_id' => $order->id, 'funding_source_id' => $request->funding_source_id,
        'warehouse_id' => $test->warehouse->id, 'received_by' => $test->actor->id, 'delivery_reference' => 'Outside delivery', 'created_at' => $date,
    ]);
    $receipt->lines()->create(['purchase_order_line_id' => $line->id, 'item_id' => $test->item->id, 'quantity' => $quantity, 'unit_cost' => '0.00']);
}

test('monthly statistics use submission and actual receipt months with separate cash quantities', function () {
    $supply = monthlyRequest($this, '1.0001', '2026-01-31 23:59:00');
    monthlyRequest($this, '2.0002', '2026-01-15 12:00:00');
    monthlyRequest($this, '999.9999', '2026-01-15 12:00:00', status: 'canceled');
    monthlyRequest($this, '999.9999', '2026-01-15 12:00:00', status: 'draft');
    monthlyRequest($this, '999.9999', '2026-01-15 12:00:00', status: 'returned');
    monthlyRequest($this, '999.9999', '2026-01-15 12:00:00', status: 'rejected');
    $cash = monthlyRequest($this, '4.0002', '2026-01-20 12:00:00', 'cash');
    monthlySupplyReceipt($this, $supply, '0.1000', '2026-02-01 00:01:00');
    monthlySupplyReceipt($this, $supply, '0.2000', '2026-02-15 12:00:00');
    monthlyOutsideReceipt($this, $cash, '1.2500', '2026-03-02 12:00:00');

    $rows = OfficeMonthlyStatistics::rows($this->actor, year: 2026);
    expect(array_column($rows, 'month'))->toBe(['2026-01', '2026-02', '2026-03'])
        ->and($rows[0]['requested_quantity'])->toBe('3.0003')->and($rows[0]['received_quantity'])->toBe('0.0000')
        ->and($rows[0]['outside_quantity'])->toBe('4.0002')->and($rows[0]['outside_received_quantity'])->toBe('0.0000')
        ->and($rows[1]['requested_quantity'])->toBe('0.0000')->and($rows[1]['received_quantity'])->toBe('0.3000')
        ->and($rows[2]['outside_received_quantity'])->toBe('1.2500')->and($rows[2]['received_quantity'])->toBe('0.0000')
        ->and(OfficeMonthlyStatistics::rows($this->actor, year: 2025))->toBe([]);
});

test('office aggregates include another staff member but exclude other funds and historical departments', function () {
    $this->actor->syncRoles(['Department Requester']);
    $colleague = User::factory()->create(['department_id' => OfficeWorkflow::departmentId()]);
    $request = monthlyRequest($this, '5.0001', '2026-01-15 12:00:00');
    $request->update(['created_by' => $colleague->id]);
    monthlySupplyReceipt($this, $request, '1.0000', '2026-01-20 12:00:00');
    monthlyRequest($this, '900.0000', '2026-01-15 12:00:00', fund: $this->otherFund);
    $historical = Department::create(['code' => 'OTHER-OFFICE', 'name' => 'Another office']);
    monthlyRequest($this, '800.0000', '2026-01-15 12:00:00', departmentId: $historical->id);

    $rows = OfficeMonthlyStatistics::rows($this->actor);
    expect($rows)->toHaveCount(1)->and($rows[0]['requested_quantity'])->toBe('5.0001')
        ->and($rows[0]['received_quantity'])->toBe('1.0000')->and($rows[0]['fund'])->toBe('GAA')
        ->and(OfficeMonthlyStatistics::rows($this->actor, $this->otherFund->id))->toBe([]);
});

test('monthly activity filters apply to receipts independently of request submission dates', function () {
    $request = monthlyRequest($this, '10.0000', '2026-01-01 12:00:00');
    monthlySupplyReceipt($this, $request, '2.5000', '2026-02-15 12:00:00');
    $rows = OfficeMonthlyStatistics::rows($this->actor, dateFrom: '2026-02-01', dateTo: '2026-02-28', fiscalYearId: $this->year->id);
    expect($rows)->toHaveCount(1)->and($rows[0]['month'])->toBe('2026-02')
        ->and($rows[0]['requested_quantity'])->toBe('0.0000')->and($rows[0]['received_quantity'])->toBe('2.5000')
        ->and(OfficeMonthlyStatistics::rows($this->actor, fiscalYearId: $this->year->id + 100))->toBe([]);
});

test('monthly CSV stays lazy and request exports identify the channel and Supply receipts', function () {
    monthlyRequest($this, '2.0001', '2026-01-15 12:00:00');
    DB::enableQueryLog();
    $response = $this->get('/app/reports/monthly-items.csv?year=2026&date_from=2026-01-01&date_to=2026-01-31')->assertOk();
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'supply_receipt_lines'))->count())->toBe(0);
    $csv = $response->streamedContent();
    expect($csv)->toContain('2026-01', 'Monthly paper', '2.0001', 'requested_quantity,received_quantity,outside_quantity,outside_received_quantity');
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'supply_receipt_lines'))->count())->toBeGreaterThan(0);
    DB::disableQueryLog();
    $requests = $this->get('/app/reports/requests.csv')->assertOk()->streamedContent();
    expect($requests)->toContain('channel,supply_received,outside_received,submitted_at', 'supply');
});

test('request CSV labels Supply and outside quantities separately after receiving', function () {
    $supply = monthlyRequest($this, '2.0000', '2026-01-15 12:00:00');
    $supply->lines()->firstOrFail()->update(['received_quantity' => '1.2500']);
    $cash = monthlyRequest($this, '4.0000', '2026-01-15 12:00:00', 'cash');
    $cash->lines()->firstOrFail()->update(['received_quantity' => '3.5000']);

    $csv = $this->get('/app/reports/requests.csv')->assertOk()->streamedContent();
    $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
    $headers = array_shift($lines);
    $rows = collect($lines)->map(fn ($line) => array_combine($headers, $line))->keyBy('channel');
    expect($rows['supply']['supply_received'])->toBe('1.2500')->and($rows['supply']['outside_received'])->toBe('0.0000')
        ->and($rows['cash']['supply_received'])->toBe('0.0000')->and($rows['cash']['outside_received'])->toBe('3.5000');
});
