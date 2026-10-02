<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Symfony\Component\Process\Process;

#[Description('Run Rector (refactoring the Pest Tests) and Pint code to format the code and apply Laravel style rules.')]
class RunCodeStyle extends Command implements PromptsForMissingInput
{
    protected $signature = 'code:fix 
                            {--dry-run : Show what would be changed with Rector without actually changing the files}
                            {--dirty : Just analyze the code files modified according to Git with Pint}';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dirty = $this->option('dirty');
        $dryRun = $this->option('dry-run');

        $this->info('Running Rector...');
        $rectorCommand = ['php ../../vendor/bin/rector', 'process', '--ansi'];
        if ($dryRun) {
            $rectorCommand[] = '--dry-run';
        }

        $rectorProcess = new Process($rectorCommand);
        $rectorProcess->setTty(Process::isTtySupported());
        $rectorProcess->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $rectorProcess->isSuccessful()) {
            $this->fail('Rector found issues or failed to run.');
        }

        $this->newLine();

        $this->info('Running Laravel Pint...');
        $pintCommand = ['php ../../vendor/bin/pint', '--ansi'];
        if ($dirty) {
            $pintCommand[] = '--dirty';
        }

        $pintProcess = new Process($pintCommand);
        $pintProcess->setTty(Process::isTtySupported());
        $pintProcess->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $pintProcess->isSuccessful()) {
            $this->fail('Laravel Pint found issues.');
        }

        $this->newLine();
        $this->info('¡Code style check completed successfully!');

        return $this::SUCCESS;
    }
}
