<?php

use App\Domain\WorkflowService;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actor = User::factory()->create(['department_id' => Department::firstOrFail()->id]);
    $this->actor->assignRole('Super Administrator');
    $this->actor->fundingSources()->sync(FundingSource::pluck('id'));
    $this->actingAs($this->actor);
});

function reportRequestFixture($test): void
{
    $lines = [];
    foreach (['A', 'B', 'C'] as $suffix) {
        $item = Item::create([
            'sku' => 'EXPORT-'.$suffix, 'name' => 'Export item '.$suffix,
            'item_category_id' => ItemCategory::firstOrFail()->id,
            'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id,
            'item_type' => 'consumable', 'minimum_stock' => '0', 'reorder_point' => '0', 'standard_cost' => '10.00',
        ]);
        $lines[] = ['item_id' => $item->id, 'quantity' => '1.0000', 'unit_price' => '10.00'];
    }
    app(WorkflowService::class)->execute($test->actor, 'request.save', [
        'funding_source_id' => FundingSource::firstOrFail()->id,
        'fiscal_year_id' => FiscalYear::where('status', 'open')->firstOrFail()->id,
        'department_id' => $test->actor->department_id,
        'required_on' => now()->addWeek()->toDateString(), 'purpose' => 'Bounded export regression', 'priority' => 'normal', 'lines' => $lines,
    ], (string) Str::uuid());
}

test('expanded report lines are capped before the PDF renderer or workbook runs', function (string $format) {
    reportRequestFixture($this);
    config(['stocknest.reports.'.$format.'_max_rows' => 2]);
    Pdf::shouldReceive('loadHTML')->never();
    Excel::shouldReceive('download')->never();

    $this->get('/app/reports/requests.'.$format)->assertStatus(422)->assertSee('Narrow the filters or export CSV');
})->with(['pdf', 'xlsx']);

test('wide report values are bounded before PDF rendering', function () {
    reportRequestFixture($this);
    config(['stocknest.reports.pdf_max_bytes' => 1]);
    Pdf::shouldReceive('loadHTML')->never();
    $this->get('/app/reports/requests.pdf')->assertStatus(422);
});

test('CSV fetches report rows only during streaming and is not truncated by printable limits', function () {
    reportRequestFixture($this);
    config(['stocknest.reports.pdf_max_rows' => 1, 'stocknest.reports.xlsx_max_rows' => 1]);
    DB::enableQueryLog();
    $response = $this->get('/app/reports/requests.csv')->assertOk();
    $beforeStream = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'item_request_lines'))->count();
    expect($beforeStream)->toBe(0);
    $csv = $response->streamedContent();
    expect($csv)->toContain('Export item A', 'Export item B', 'Export item C', '1.0000', 'requested,approved,fulfilled');
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'item_request_lines'))->count())->toBeGreaterThan(0);
    DB::disableQueryLog();
});

test('exports share a per-user rate limit across report formats', function () {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->get('/app/reports/stock.csv')->assertOk();
    }
    $this->get('/app/reports/stock.xlsx')->assertStatus(429)->assertHeader('Retry-After');
});
