<?php

use App\Domain\WorkflowService;
use App\Models\ItemRequest;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
    fwrite(STDERR, 'This worker requires a dedicated testing database ending in _test.');
    exit(2);
}
[$script, $requestId, $userId, $warehouseId, $key, $barrier] = $argv;
$request = ItemRequest::findOrFail((int) $requestId);
echo "ready\n";
flush();
$timeout = microtime(true) + 75;
while (! is_file($barrier)) {
    if (microtime(true) > $timeout) {
        fwrite(STDERR, 'Barrier timeout');
        exit(2);
    }
    usleep(10000);
}
try {
    app(WorkflowService::class)->execute(User::findOrFail((int) $userId), 'issuance.create', [
        'id' => $request->id, 'warehouse_id' => (int) $warehouseId, 'recipient' => 'Concurrent test recipient',
        'lines' => [['line_id' => $request->lines()->firstOrFail()->id, 'quantity' => '10']],
    ], $key);
    echo "issued\n";
} catch (ValidationException $exception) {
    echo "denied\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(2);
}
