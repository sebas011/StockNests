<?php

use App\Domain\OfficeWorkflow;
use App\Domain\WorkflowService;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemRequest;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

test('PostgreSQL serializes simultaneous Supply submissions against one annual item allowance', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL: SQLite does not implement row locks.');
    }
    if (! app()->environment('testing') || ! str_ends_with((string) DB::getDatabaseName(), '_test')) {
        throw new RuntimeException('Concurrency tests require a dedicated testing database ending in _test.');
    }
    config(['stocknest.office_workflow' => true]);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $fund = FundingSource::where('code', 'GAA')->firstOrFail();
    $year = FiscalYear::firstOrFail();
    $actor = User::factory()->create(['department_id' => OfficeWorkflow::departmentId()]);
    $actor->assignRole('Super Administrator');
    $actor->fundingSources()->sync([$fund->id]);
    $item = Item::create(['sku' => 'SUPPLY-CONCURRENT', 'name' => 'Annual allowance fixture', 'item_category_id' => ItemCategory::firstOrFail()->id, 'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id, 'standard_cost' => '1.00']);
    $scope = ['funding_source_id' => $fund->id, 'fiscal_year_id' => $year->id, 'department_id' => OfficeWorkflow::departmentId()];
    $run = fn ($operation, $data) => app(WorkflowService::class)->execute($actor, $operation, $data, (string) Str::uuid());
    $run('projection.save', $scope + ['justification' => 'External annual approval', 'lines' => [['item_id' => $item->id, 'quantity' => '10', 'unit_price' => '1.00']]]);
    $requests = [];
    foreach ([0, 1] as $index) {
        $requests[] = $run('request.save', $scope + ['required_on' => today()->toDateString(), 'purpose' => 'Concurrent Supply request '.$index, 'channel' => 'supply', 'lines' => [['item_id' => $item->id, 'quantity' => '7', 'unit_price' => '1.00']]])['id'];
    }
    $barrier = sys_get_temp_dir().'/stocknest-supply-barrier-'.Str::uuid();
    $connection = config('database.connections.pgsql');
    $environment = ['APP_ENV' => 'testing', 'STOCKNEST_OFFICE_WORKFLOW' => 'true', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
    $processes = [];
    try {
        foreach ($requests as $request) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/supply-submit-worker.php'), (string) $request, (string) $actor->id, (string) Str::uuid(), $barrier], base_path(), $environment);
            $process->setTimeout(90);
            $process->start();
            $processes[] = $process;
        }
        $timeout = microtime(true) + 60;
        while (! collect($processes)->every(fn ($process) => str_contains($process->getOutput(), 'ready'))) {
            if (microtime(true) > $timeout || collect($processes)->contains(fn ($process) => ! $process->isRunning())) {
                throw new RuntimeException('Workers did not reach the Supply submission barrier. '.implode(' ', array_map(fn ($process) => $process->getErrorOutput(), $processes)));
            }
            usleep(10000);
        }
        file_put_contents($barrier, 'release');
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        }
        $results = array_map(fn ($process) => trim(str_replace('ready', '', $process->getOutput())), $processes);
        sort($results);
        expect($results)->toBe(['denied', 'submitted'])->and(ItemRequest::where('status', 'submitted')->count())->toBe(1)
            ->and(OfficeWorkflow::allowanceRows($actor)[0]['remaining_quantity'])->toBe('3.0000');
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        if (is_file($barrier)) {
            unlink($barrier);
        }
    }
});
