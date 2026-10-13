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
                'warning' => \Log::warning($formattedMessage, $this->sanitizeLogContext($context)),
                'error' => \Log::error($formattedMessage, $this->sanitizeLogContext($context)),
                'debug' => \Log::debug($formattedMessage, $this->sanitizeLogContext($context)),
                default => \Log::info($formattedMessage, $this->sanitizeLogContext($context)),
            };
        }
    }

    protected function sanitizeLogContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'authorization', 'secret'], true)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitizeLogContext($value);
            }
        }

        return $context;
    }
}
