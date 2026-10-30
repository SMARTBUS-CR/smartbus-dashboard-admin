<?php

namespace Tests\Support;

use App\Models\Company;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;

trait WithDriverConcurrency
{
    /** Committed fixtures must be visible to independent worker connections. */
    protected array $connectionsToTransact = [];

    /** @var list<DriverRegistrationProcess> */
    private array $driverProcesses = [];

    /** @var list<string> */
    private array $concurrentCompanyIds = [];

    protected function createConcurrentCompany(): Company
    {
        $company = createCompany();
        $this->concurrentCompanyIds[] = $company->id;

        return $company;
    }

    /** @param array<string, mixed> $input */
    protected function startDriverProcess(array $input): DriverRegistrationProcess
    {
        $this->ensureTestingDatabases();
        $parser = new ConfigurationUrlParser;
        $input['connections'] = [
            'pgsql' => $parser->parseConfiguration(config('database.connections.pgsql')),
            'mysql' => $parser->parseConfiguration(config('database.connections.mysql')),
        ];
        $process = new DriverRegistrationProcess($input);
        $this->driverProcesses[] = $process;

        return $process;
    }

    protected function finishDriverConcurrency(): void
    {
        $this->ensureTestingDatabases();

        foreach ($this->driverProcesses as $process) {
            $process->close();
        }

        DB::connection('pgsql')->table('driver_schedules')->whereIn('driver_id',
            DB::connection('pgsql')->table('drivers')->select('id')->whereIn('company_id', $this->concurrentCompanyIds)
        )->delete();

        foreach (['drivers', 'company_users', 'companies'] as $table) {
            DB::connection('pgsql')->table($table)
                ->whereIn($table === 'companies' ? 'id' : 'company_id', $this->concurrentCompanyIds)->delete();
        }
    }
}
