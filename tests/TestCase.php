<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

use function str_ends_with;

abstract class TestCase extends BaseTestCase
{
    /**
     * Abort if any database connection does not point to a testing database,
     * so a misconfigured run can never wipe development data.
     */
    protected function setUpTraits(): array
    {
        $this->ensureTestingDatabases();

        return parent::setUpTraits();
    }

    protected function ensureTestingDatabases(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'pgsql') {
            throw new RuntimeException('Tests require APP_ENV=testing and the pgsql default connection.');
        }

        config([
            'database.connections.pgsql.database' => env('DB_PGSQL_DATABASE', 'smartbus_testing'),
            'database.connections.mysql.database' => env('DB_MYSQL_DATABASE', 'smartbus_users_testing'),
        ]);

        foreach (['pgsql', 'mysql'] as $connection) {
            $configuration = (new ConfigurationUrlParser)->parseConfiguration(config("database.connections.{$connection}"));
            $database = (string) ($configuration['database'] ?? '');

            if (! str_ends_with($database, '_testing')) {
                throw new RuntimeException(
                    "Refusing to run tests: [{$connection}] database [{$database}] does not end in _testing."
                );
            }

            foreach (['read', 'write'] as $mode) {
                if (isset($configuration[$mode]['database']) && ! str_ends_with($configuration[$mode]['database'], '_testing')) {
                    throw new RuntimeException("Refusing to run tests: unsafe [{$connection}.{$mode}] database.");
                }
            }
        }
    }
}
