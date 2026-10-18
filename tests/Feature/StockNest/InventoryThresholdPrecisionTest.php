<?php

use App\Models\Department;
use App\Models\FundingSource;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockBalance;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;

test('fractional stock thresholds remain precise when evaluating low stock', function () {
    $this->seed(DatabaseSeeder::class);
    $fund = FundingSource::firstOrFail();
    $actor = User::factory()->create(['department_id' => Department::firstOrFail()->id]);
    $actor->assignRole('Super Administrator');
    $actor->fundingSources()->sync([$fund->id]);
    $item = Item::create([
        'sku' => 'FRACTIONAL-THRESHOLD',
        'name' => 'Fractional inventory item',
        'item_category_id' => ItemCategory::firstOrFail()->id,
        'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id,
        'minimum_stock' => '0.1250',
        'reorder_point' => '0.2500',
        'standard_cost' => '10.00',
    ]);
    StockBalance::create([
        'funding_source_id' => $fund->id,
        'warehouse_id' => Warehouse::firstOrFail()->id,
        'item_id' => $item->id,
        'quantity' => '0.2000',
    ]);

    expect($item->fresh()->minimum_stock)->toBe('0.1250')
        ->and($item->fresh()->reorder_point)->toBe('0.2500');

    $csv = $this->actingAs($actor)->get('/app/reports/stock.csv')->assertOk()->streamedContent();
    expect($csv)->toContain('FRACTIONAL-THRESHOLD', '0.2000,0.0000,0.2500,Yes');
});
