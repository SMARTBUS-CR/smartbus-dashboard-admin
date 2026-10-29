<?php

namespace Tests\Support;

use App\Models\Company;
use Closure;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait WithDriverCompensation
{
    /** Real COMMIT failures cannot be exercised inside RefreshDatabase savepoints. */
    protected array $connectionsToTransact = [];

    /** @var list<string> */
    private array $compensationCompanyIds = [];

    protected function createCompensationCompany(): Company
    {
        $company = createCompany();
        $this->compensationCompanyIds[] = $company->id;

        return $company;
    }

    /** @return array<string, list<array<string, mixed>>> */
    protected function registrationDatabaseSnapshot(): array
    {
        $snapshot = [];

        foreach (['pgsql' => ['drivers', 'company_users'], 'mysql' => ['users', 'roles', 'model_has_roles', 'model_has_permissions', 'role_has_permissions']] as $connection => $tables) {
            foreach ($tables as $table) {
                $rows = DB::connection($connection)->table($table)->get()->map(fn (object $row): array => (array) $row)->all();
                usort($rows, fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
                $snapshot[$connection.'.'.$table] = $rows;
            }
        }

        return $snapshot;
    }

    /** @param Closure(): void $observe
     * @return Closure(): void
     */
    protected function injectRegistrationFailure(string $phase, Closure $observe): Closure
    {
        $connectionName = str_starts_with($phase, 'mysql') ? 'mysql' : 'pgsql';
        $connection = DB::connection($connectionName);
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        $connection->setEventDispatcher($dispatcher);
        $armed = true;
        $eventClass = str_ends_with($phase, 'committed') ? TransactionCommitted::class : TransactionCommitting::class;

        $dispatcher->listen($eventClass, function (object $event) use ($connectionName, $phase, $observe, &$armed): void {
            if (! $armed || $event->connectionName !== $connectionName || (str_ends_with($phase, 'committed') && $event->connection->transactionLevel() !== 0)) {
                return;
            }

            $armed = false;
            $observe();

            if ($phase !== 'pgsql deferred commit') {
                throw new RuntimeException('Injected registration failure');
            }
        });

        if ($phase === 'pgsql deferred commit') {
            DB::connection('pgsql')->unprepared(<<<'SQL'
                CREATE FUNCTION reject_driver_registration_commit() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'Injected registration failure'; END;
                $$ LANGUAGE plpgsql;
                CREATE CONSTRAINT TRIGGER reject_driver_registration_commit
                AFTER INSERT ON drivers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION reject_driver_registration_commit();
                SQL);
        }

        return function () use ($connection, $originalDispatcher, $phase): void {
            $connection->setEventDispatcher($originalDispatcher);

            if ($phase === 'pgsql deferred commit') {
                DB::connection('pgsql')->unprepared('DROP TRIGGER IF EXISTS reject_driver_registration_commit ON drivers; DROP FUNCTION IF EXISTS reject_driver_registration_commit();');
            }
        };
    }

    protected function tearDownWithDriverCompensation(): void
    {
        $this->ensureTestingDatabases();

        foreach (['pgsql', 'mysql'] as $name) {
            $connection = DB::connection($name);
            $connection->rollBack(0);

            if ($connection->getPdo()->inTransaction()) {
                $connection->getPdo()->rollBack();
            }
        }

        foreach (['drivers', 'company_users', 'companies'] as $table) {
            DB::connection('pgsql')->table($table)
                ->whereIn($table === 'companies' ? 'id' : 'company_id', $this->compensationCompanyIds)->delete();
        }
    }
}
