<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExternalAuthService
{
    public function authenticate(string $email, string $password): ?array
    {
        try {
            $response = Http::timeout(config('api-login.timeout', 30))
                ->withOptions(['verify' => false])
                ->acceptJson()
                ->post(config('api-login.api_url'), compact('email', 'password'));

            if (! $response->successful()) {
                $this->logWarning('External API authentication failed', $email, [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                return null;
            }

            $json = $response->json();
            $token = $json['meta']['access_token'] ?? null;
            $userData = $json['data'] ?? null;

            if (! $token || ! $userData) {
                return null;
            }

            return [
                'token' => $token,
                'data' => [
                    'id' => $userData['id'] ?? null, 
                    'type' => $userData['type'] ?? null,
                    ...$userData['attributes'] ?? []
                ],
            ];

        } catch (Throwable $e) {
            $this->logWarning('External API authentication error', $email, ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getUser(string $token): ?array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->get(config('services.smartbus.gateway.url') . '/auth/user');

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function logout(string $token): bool
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->post(config('services.smartbus.gateway.url') . '/auth/logout');

        return $response->successful();
    }

    public function sendPasswordResetCode(string $email): bool
    {
        $response = Http::acceptJson()
            ->post(config('services.smartbus.gateway.url').'/auth/password/forgot', [
                'email' => $email,
            ]);

        return $response->successful();
    }

    public function resetPassword(string $email, string $code, string $password): bool
    {
        $response = Http::acceptJson()
            ->post(config('services.smartbus.gateway.url').'/auth/password/reset', [
                'email' => $email,
                'code' => $code,
                'password' => $password,
                'password_confirmation' => $password,
            ]);

        return $response->successful();
    }

    private function logWarning(string $message, string $email, array $context): void
    {
        if (config('api-login.log_failures', true)) {
            Log::warning($message, array_merge(['email' => $email], $context));
        }
    }
}