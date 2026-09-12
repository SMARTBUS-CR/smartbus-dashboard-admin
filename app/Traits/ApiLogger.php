<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait ApiLogger
{
    protected function log(string $type, string $message, array $context = []): void
    {
        if (config('services.smartbus.gateway.log_failures', true)) {
            match ($type) {
                'warning' => Log::warning($message, [...$context]),
                'error' => Log::error($message, [...$context]),
                'debug' => Log::debug($message, [...$context]),
                default => Log::info($message, [...$context]),
            };
        }
    }
}
