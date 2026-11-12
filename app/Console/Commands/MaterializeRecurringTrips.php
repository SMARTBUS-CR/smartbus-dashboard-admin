<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\TripAssignmentMaterializationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MaterializeRecurringTrips extends Command
{
    protected $signature = 'trips:materialize-recurring
                            {--company= : Process only this Company UUID}
                            {--date= : First Company-local service date in YYYY-MM-DD format}
                            {--days=2 : Number of consecutive local dates to process (1-31)}';

    protected $description = 'Materialize recurring assignments into scheduled daily Trips';

    public function handle(TripAssignmentMaterializationService $materializer): int
    {
        if ($this->optionWasProvidedWithoutValue('company')) {
            $this->components->error('The --company option requires a Company UUID.');

            return self::INVALID;
        }

        if ($this->optionWasProvidedWithoutValue('date')) {
            $this->components->error('The --date option requires a YYYY-MM-DD value.');

            return self::INVALID;
        }

        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 31) {
            $this->components->error('The --days option must be an integer between 1 and 31.');

            return self::INVALID;
        }

        $date = $this->option('date');
        if ($date !== null && ! $this->isDate($date)) {
            $this->components->error('The --date option must use the YYYY-MM-DD format.');

            return self::INVALID;
        }

        $query = Company::query()->where('status', CompanyStatus::ACTIVE)->orderBy('id');
        if (($companyId = $this->option('company')) !== null) {
            if (! is_string($companyId) || ! Str::isUuid($companyId)) {
                $this->components->error('The --company option must be a valid Company UUID.');

                return self::INVALID;
            }

            $query->whereKey($companyId);
            if (! (clone $query)->exists()) {
                $this->components->error('The requested active Company was not found.');

                return self::INVALID;
            }
        }

        $totals = $this->emptyTotals();
        $query->select(['id', 'timezone'])->chunkById(50, function ($companies) use ($materializer, $date, $days, &$totals): void {
            foreach ($companies as $company) {
                $firstDate = $date === null
                    ? CarbonImmutable::now($company->timezone)->startOfDay()
                    : CarbonImmutable::createFromFormat('!Y-m-d', $date, $company->timezone);

                for ($offset = 0; $offset < $days; $offset++) {
                    try {
                        $result = $materializer->materialize($company, $firstDate->addDays($offset));
                        $this->addResult($totals, $result);
                    } catch (Throwable $exception) {
                        report($exception);
                        $totals['failed']++;
                    }
                }
            }
        });

        $this->table(['Created', 'Existing', 'Suspended', 'Ineligible', 'Conflicting', 'Failed'], [[
            $totals['created'], $totals['existing'], $totals['suspended'],
            $totals['ineligible'], $totals['conflicting'], $totals['failed'],
        ]]);

        Log::info('Recurring Trip materialization completed.', $totals);

        return ($totals['ineligible'] + $totals['conflicting'] + $totals['failed']) > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function optionWasProvidedWithoutValue(string $option): bool
    {
        return $this->input->hasParameterOption("--{$option}")
            && blank($this->option($option));
    }

    private function isDate(mixed $date): bool
    {
        if (! is_string($date)) {
            return false;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        } catch (Throwable) {
            return false;
        }

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** @return array{created: int, existing: int, suspended: int, ineligible: int, conflicting: int, failed: int} */
    private function emptyTotals(): array
    {
        return array_fill_keys(['created', 'existing', 'suspended', 'ineligible', 'conflicting', 'failed'], 0);
    }

    /** @param array<string, int> $totals */
    private function addResult(array &$totals, array $result): void
    {
        $totals['created'] += count($result['created']);
        foreach ($result['skipped'] as $skipped) {
            $totals[$skipped['reason'] === 'already_materialized' ? 'existing' : 'suspended']++;
        }
        foreach ($result['failed'] as $failed) {
            $category = $failed['reason'] ?? 'failed';
            $totals[array_key_exists($category, $totals) ? $category : 'failed']++;
        }
    }
}
