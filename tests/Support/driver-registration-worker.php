<?php

use App\Models\Company;
use App\Models\User;
use App\Services\DriverManagementService;
use App\Services\UserManagementService;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$socket = stream_socket_client('tcp://'.$input['barrier'], $errorCode, $errorMessage, 20);

if ($socket === false) {
    throw new RuntimeException('Cannot connect to concurrency barrier.');
}

stream_set_timeout($socket, 20);
$send = static function (string $event, array $data = []) use ($socket): void {
    fwrite($socket, json_encode(['event' => $event, ...$data], JSON_THROW_ON_ERROR)."\n");
    fflush($socket);
};
$pause = static function (string $event) use ($socket, $send): void {
    $send($event);

    if (fgets($socket) !== "continue\n") {
        throw new RuntimeException('Concurrency barrier was not released.');
    }
};

try {
    foreach ($input['connections'] as $configuration) {
        if (! str_ends_with($configuration['database'], '_testing')) {
            throw new RuntimeException('Concurrency workers require isolated testing databases.');
        }
    }

    $environment = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
        'DB_DATABASE' => $input['connections']['pgsql']['database'],
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4',
    ];

    foreach (['pgsql' => 'PGSQL', 'mysql' => 'MYSQL'] as $name => $prefix) {
        foreach (['host' => 'HOST', 'port' => 'PORT', 'database' => 'DATABASE', 'username' => 'USERNAME', 'password' => 'PASSWORD'] as $key => $suffix) {
            $environment['DB_'.$prefix.'_'.$suffix] = $input['connections'][$name][$key] ?? '';
        }
    }

    foreach ($environment as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = (string) $value;
    }

    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';

    if ($app->configurationIsCached()) {
        throw new RuntimeException('Concurrency workers require uncached configuration.');
    }

    $app->make(Kernel::class)->bootstrap();
    config(['database.connections.pgsql' => $input['connections']['pgsql'], 'database.connections.mysql' => $input['connections']['mysql']]);

    if (! app()->environment('testing') || config('database.default') !== 'pgsql') {
        throw new RuntimeException('Concurrency worker environment is unsafe.');
    }

    foreach (['pgsql', 'mysql'] as $name) {
        $configuration = (new ConfigurationUrlParser)->parseConfiguration(config('database.connections.'.$name));

        foreach ([$configuration, $configuration['read'] ?? $configuration, $configuration['write'] ?? $configuration] as $endpoint) {
            if (! str_ends_with($endpoint['database'] ?? $configuration['database'], '_testing')) {
                throw new RuntimeException('Concurrency worker connection is unsafe.');
            }
        }
    }

    Http::preventStrayRequests();
    app()->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
    {
        public function verify(mixed $data): bool
        {
            return true;
        }
    });
    DB::connection('pgsql')->statement("SET lock_timeout = '8s'");
    DB::connection('mysql')->statement('SET SESSION innodb_lock_wait_timeout = 4');
    app()->setLocale($input['locale'] ?? 'en');
    $company = Company::query()->findOrFail($input['company_id']);
    $actor = User::query()->findOrFail($input['actor_id']);
    $target = isset($input['user_id']) ? User::query()->findOrFail($input['user_id']) : null;
    setPermissionsTeamId($input['previous_team']);
    $actor->load('roles', 'permissions');
    $target?->load('roles', 'permissions');
    $paused = false;

    DB::listen(static function (QueryExecuted $query) use ($input, $pause, &$paused): void {
        if ($paused || $query->connectionName !== 'pgsql') {
            return;
        }

        if ($input['barrier_at'] === 'license' && str_starts_with($query->sql, 'select exists') && str_contains($query->sql, '"drivers"') && str_contains($query->sql, '"license"')) {
            $paused = true;
            $pause('license_checked');
        } elseif ($input['barrier_at'] === 'removal' && str_contains($query->sql, '"companies"') && str_contains($query->sql, 'for update')) {
            $paused = true;
            $pause('company_locked');
        }
    });

    DB::connection('pgsql')->beforeExecuting(static function (string $sql, array $bindings, Connection $connection) use ($input, $pause, &$paused): void {
        if ($paused || $input['barrier_at'] !== 'registration') {
            return;
        }

        if ((str_contains($sql, '"companies"') && str_contains($sql, 'for share')) || str_starts_with($sql, 'insert into "drivers"')) {
            $paused = true;
            $pause('registration_write_attempt');
        }
    });

    $send('ready', ['pgsql_pid' => DB::connection('pgsql')->selectOne('select pg_backend_pid() as id')->id]);
    $result = [];

    try {
        if ($input['operation'] === 'remove') {
            Filament::setTenant($company, isQuiet: true);
            app(UserManagementService::class)->removeCompanyAccess($actor, $target);
        } elseif ($input['operation'] === 'register') {
            $driver = app(DriverManagementService::class)->register($actor, $company, $input['identity'], $input['employment']);
            $result = ['driver_id' => $driver->id, 'user_id' => $driver->user_id];
        } else {
            $driver = app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $input['employment']);
            $result = ['driver_id' => $driver->id, 'user_id' => $driver->user_id];
        }
        $result['status'] = 'success';
    } catch (ValidationException $exception) {
        $result = ['status' => 'validation', 'errors' => $exception->errors()];
    } catch (QueryException $exception) {
        $result = [
            'status' => 'database', 'connection' => $exception->getConnectionName(),
            'sqlstate' => $exception->errorInfo[0] ?? null, 'driver_code' => $exception->errorInfo[1] ?? null,
        ];
    }

    $send('result', [
        ...$result,
        'team_restored' => getPermissionsTeamId() === $input['previous_team'],
        'actor_cache_cleared' => ! $actor->relationLoaded('roles') && ! $actor->relationLoaded('permissions'),
        'target_cache_cleared' => $target === null || (! $target->relationLoaded('roles') && ! $target->relationLoaded('permissions')),
        'pgsql_level' => DB::connection('pgsql')->transactionLevel(),
        'mysql_level' => DB::connection('mysql')->transactionLevel(),
        'pgsql_transaction' => DB::connection('pgsql')->getPdo()->inTransaction(),
        'mysql_transaction' => DB::connection('mysql')->getPdo()->inTransaction(),
    ]);
} catch (Throwable $exception) {
    $send('failure', ['class' => $exception::class, 'message' => $exception->getMessage()]);
} finally {
    fclose($socket);
}
