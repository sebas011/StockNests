<?php

use App\Domain\WorkflowService;
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
[$script, $requestId, $userId, $key, $barrier] = $argv;
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
    app(WorkflowService::class)->execute(User::findOrFail((int) $userId), 'request.submit', ['id' => (int) $requestId], $key);
    echo "submitted\n";
} catch (ValidationException $exception) {
    echo "denied\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(2);
}
