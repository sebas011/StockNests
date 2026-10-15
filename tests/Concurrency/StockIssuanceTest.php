<?php

use App\Domain\WorkflowService;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\InventoryCount;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockBalance;
use App\Models\StockIssuance;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL 18: SQLite does not implement row locks.');
    }
    if (! app()->environment('testing') || ! str_ends_with((string) DB::getDatabaseName(), '_test')) {
        throw new RuntimeException('Concurrency tests require APP_ENV=testing and a dedicated database ending in _test.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $fund = FundingSource::where('code', 'GAA')->firstOrFail();
    $department = Department::where('code', 'OPS')->firstOrFail();
    $year = FiscalYear::firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    $item = Item::create(['sku' => 'CONCURRENT', 'name' => 'Concurrency fixture', 'item_category_id' => ItemCategory::firstOrFail()->id, 'unit_of_measure_id' => UnitOfMeasure::firstOrFail()->id, 'standard_cost' => '1.00']);
    $users = [];
    foreach (['requester' => 'Department Requester', 'head' => 'Department Head', 'approver' => 'Fund Approver', 'custodian' => 'Inventory Custodian'] as $name => $role) {
        $user = User::factory()->create(['department_id' => $department->id]);
        $user->assignRole($role);
        $user->fundingSources()->sync([$fund->id]);
        $users[$name] = $user;
    }
    $service = app(WorkflowService::class);
    $run = fn ($actor, $operation, $data) => $service->execute($users[$actor], $operation, $data, (string) Str::uuid());
    $scope = ['funding_source_id' => $fund->id, 'fiscal_year_id' => $year->id, 'department_id' => $department->id];
    $count = $run('custodian', 'count.create', $scope + ['warehouse_id' => $warehouse->id, 'counter_id' => $users['custodian']->id, 'count_month' => now()->startOfMonth()->format('Y-m-d')]);
    $countLine = InventoryCount::findOrFail($count['id'])->lines()->firstOrFail();
    $run('custodian', 'count.encode', ['id' => $count['id'], 'lines' => [['line_id' => $countLine->id, 'physical_quantity' => '10', 'variance_reason' => 'Verified opening count']]]);
    $run('custodian', 'count.submit', ['id' => $count['id']]);
    $run('approver', 'count.decide', ['id' => $count['id'], 'decision' => 'approved']);
    $run('custodian', 'count.reconcile', ['id' => $count['id']]);
    $requests = [];
    for ($index = 0; $index < 2; $index++) {
        $request = $run('requester', 'request.save', $scope + ['required_on' => now()->addWeek()->format('Y-m-d'), 'purpose' => 'Concurrent requirement', 'lines' => [['item_id' => $item->id, 'quantity' => '10', 'unit_price' => '1.00']]]);
        $run('requester', 'request.submit', ['id' => $request['id']]);
        $run('head', 'request.decide', ['id' => $request['id'], 'decision' => 'approved']);
        $run('approver', 'request.decide', ['id' => $request['id'], 'decision' => 'approved', 'comment' => 'Unplanned item authorized']);
        $requests[] = $request['id'];
    }
    $this->fixture = compact('users', 'warehouse', 'requests');
});

function concurrentIssuances(array $fixture, bool $duplicate): array
{
    $barrier = sys_get_temp_dir().'/stocknest-barrier-'.Str::uuid();
    $key = (string) Str::uuid();
    $processes = [];
    $connection = config('database.connections.pgsql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
    try {
        foreach ([0, 1] as $index) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/issue-worker.php'), (string) $fixture['requests'][$duplicate ? 0 : $index], (string) $fixture['users']['custodian']->id, (string) $fixture['warehouse']->id, $duplicate ? $key : (string) Str::uuid(), $barrier], base_path(), $environment);
            $process->setTimeout(90);
            $process->start();
            $processes[] = $process;
        }
        $timeout = microtime(true) + 60;
        while (! collect($processes)->every(fn ($process) => str_contains($process->getOutput(), 'ready'))) {
            if (microtime(true) > $timeout || collect($processes)->contains(fn ($process) => ! $process->isRunning())) {
                throw new RuntimeException('Workers did not reach the concurrency barrier. '.implode(' ', array_map(fn ($p) => $p->getErrorOutput(), $processes)));
            }
            usleep(10000);
        }
        file_put_contents($barrier, 'release');
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        }

        return array_map(fn ($p) => trim(str_replace('ready', '', $p->getOutput())), $processes);
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
}

test('PostgreSQL serializes simultaneous attempts to issue the last ten units', function () {
    $results = concurrentIssuances($this->fixture, false);
    sort($results);
    expect($results)->toBe(['denied', 'issued'])->and(StockBalance::firstOrFail()->quantity)->toBe('0.0000')->and(StockIssuance::count())->toBe(1);
});

test('PostgreSQL concurrent retries of one issuance create one ledger effect', function () {
    expect(concurrentIssuances($this->fixture, true))->toBe(['issued', 'issued'])->and(StockBalance::firstOrFail()->quantity)->toBe('0.0000')->and(StockIssuance::count())->toBe(1);
});
