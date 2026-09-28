<?php

namespace App\Traits;

trait ApiLogger
{
    protected function log(string $type, string $message, array $context = []): void
    {
        if (config('services.smartbus.gateway.log_failures', true)) {
            $className = static::class;
            $formattedMessage = "[{$className}] {$message}";

            match ($type) {
                'warning' => \Log::warning($formattedMessage, [...$context]),
                'error' => \Log::error($formattedMessage, [...$context]),
                'debug' => \Log::debug($formattedMessage, [...$context]),
                default => \Log::info($formattedMessage, [...$context]),
            };
        }
    }
}
