<?php

use App\Models\AnnualProjection;
use App\Models\BudgetAdjustment;
use App\Models\BudgetAllocation;
use App\Models\Department;
use App\Models\FinancialEntry;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
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
use App\Models\StockReservation;
use App\Models\User;
use App\Policies\StockNestPolicy;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actor = User::factory()->create(['department_id' => Department::firstOrFail()->id]);
    $this->actor->assignRole('Inventory Custodian');
    $this->localFund = FundingSource::where('code', 'GAA')->firstOrFail();
    $this->otherFund = FundingSource::where('code', 'APPCSE')->firstOrFail();
    $this->actor->fundingSources()->sync([$this->localFund->id]);
});

test('indirect records inherit the fund scope of every owning record', function () {
    $policy = new StockNestPolicy;
    foreach ([$this->localFund, $this->otherFund] as $fund) {
        $attributes = ['funding_source_id' => $fund->id, 'department_id' => $this->actor->department_id, 'created_by' => $this->actor->id];
        $allocation = new BudgetAllocation($attributes);
        $request = new ItemRequest($attributes);
        $requestLine = (new ItemRequestLine)->setRelation('request', $request);
        $order = new PurchaseOrder($attributes);
        $balance = new StockBalance($attributes);
        $receipt = (new GoodsReceipt($attributes))->setRelation('order', $order);
        $issuance = (new StockIssuance($attributes))->setRelation('request', $request);
        $records = [
            (new BudgetAdjustment)->setRelation('allocation', $allocation),
            (new FinancialEntry)->setRelation('allocation', $allocation)->setRelation('order', $order),
            (new StockReservation)->setRelation('balance', $balance)->setRelation('requestLine', $requestLine),
            (new StockMovement)->setRelation('balance', $balance),
            (new ProjectionLine)->setRelation('projection', new AnnualProjection($attributes)),
            $requestLine,
            (new PurchaseOrderLine)->setRelation('order', $order),
            (new GoodsReceiptLine)->setRelation('receipt', $receipt),
            (new InventoryCountLine)->setRelation('count', new InventoryCount($attributes)),
            (new StockIssuanceLine)->setRelation('issuance', $issuance),
        ];
        foreach ($records as $record) {
            expect($policy->view($this->actor, $record))->toBe($fund->id === $this->localFund->id, $record::class);
        }
    }
    expect($policy->view($this->actor, new FiscalYear))->toBeTrue();
    expect($policy->view($this->actor, new StockReservation))->toBeFalse();
});

test('request-line policy preserves requester ownership as well as fund scope', function () {
    $this->actor->syncRoles(['Department Requester']);
    $parent = new ItemRequest(['funding_source_id' => $this->localFund->id, 'department_id' => $this->actor->department_id, 'created_by' => $this->actor->id + 1]);
    $line = (new ItemRequestLine)->setRelation('request', $parent);
    expect((new StockNestPolicy)->view($this->actor, $line))->toBeFalse();
    $parent->created_by = $this->actor->id;
    expect((new StockNestPolicy)->view($this->actor, $line))->toBeTrue();
});
