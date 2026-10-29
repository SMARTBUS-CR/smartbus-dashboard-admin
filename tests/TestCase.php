<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

use function str_ends_with;

abstract class TestCase extends BaseTestCase
{
    /**
     * Point both connections at the testing databases and then validate them,
     * so a misconfigured run can never wipe development data.
     *
     * This runs before RefreshDatabase fires because setUpTraits() is the
     * correct hook — setUp() would be too late.
     */
    protected function setUpTraits(): array
    {
        $this->pointInTestingDatabases();
        $this->ensureTestingDatabases();

        return parent::setUpTraits();
    }

    /**
     * Override both connection database names from the phpunit.xml env vars.
     *
     * Separated from {@see ensureTestingDatabases()} so that tests can mutate
     * config and call ensureTestingDatabases() independently to assert the
     * guard fires — if the override and the guard lived in the same method,
     * the override would always reset what the test changed before the check ran.
     */
    private function pointInTestingDatabases(): void
    {
        config([
            'database.connections.pgsql.database' => env('DB_PGSQL_DATABASE', 'smartbus_testing'),
            'database.connections.mysql.database' => env('DB_MYSQL_DATABASE', 'smartbus_users_testing'),
        ]);
    }

    /**
     * Abort if any database connection does not point to a testing database.
     *
     * This is a pure validator: it reads the current runtime config and throws
     * if anything looks unsafe. It intentionally does NOT reset the config, so
     * that DatabaseSafetyTest can mutate a connection and verify this throws.
     */
    protected function ensureTestingDatabases(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'pgsql') {
            throw new RuntimeException('Tests require APP_ENV=testing and the pgsql default connection.');
        }

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
