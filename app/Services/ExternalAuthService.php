<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExternalAuthService
{
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.smartbus.gateway.url', env('API_GATEWAY_URL', 'http://127.0.0.1:8001/api')), '/');
        $this->timeout = (int) config('services.smartbus.gateway.timeout', 30);
    }

    /**
     * Authenticate credentials against API Gateway /auth/login.
     *
     * @return array{access_token: string, token_type: string, expires_at: ?string, user: array<string, mixed>}|null
     */
    public function authenticate(string $email, string $password): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withQueryParameters([
                    'include' => 'roles',
                ])
                ->accept('application/vnd.api+json, application/json')
                ->post("{$this->baseUrl}/auth/login", [
                    'email' => $email,
                    'password' => $password,
                ]);

            if (! $response->successful()) {
                $this->logWarning('External API authentication failed', $email, [
                    'status' => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ]);

                return null;
            }

            $json = $response->json();
            $token = $json['meta']['access_token'] ?? null;
            $userData = $json['data'] ?? null;

            if (! $token || ! $userData) {
                return null;
            }

            $roles = $this->extractRoles($json);
            $permissions = $this->extractPermissions($json);

            return [
                'access_token' => $token,
                'token_type' => $json['meta']['token_type'] ?? 'Bearer',
                'expires_at' => $json['meta']['expires_at'] ?? null,
                'user' => [
                    'id' => $userData['id'] ?? null,
                    'type' => $userData['type'] ?? 'users',
                    ...$userData['attributes'] ?? [],
                    'roles' => $roles,
                    'permissions' => $permissions,
                    'relationships' => $userData['relationships'] ?? [],
                    'included' => $json['included'] ?? [],
                ],
            ];
        } catch (Throwable $e) {
            $this->logWarning('External API authentication error', $email, ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get authenticated user info from API Gateway /auth/user.
     */
    public function getUser(string $token): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->withQueryParameters([
                    'include' => 'roles',
                ])
                ->accept('application/vnd.api+json, application/json')
                ->get("{$this->baseUrl}/auth/user");

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            $userData = $json['data'] ?? null;

            if (! $userData) {
                return null;
            }

            $roles = $this->extractRoles($json);
            $permissions = $this->extractPermissions($json);

            return [
                'id' => $userData['id'] ?? null,
                'type' => $userData['type'] ?? 'users',
                ...$userData['attributes'] ?? [],
                'roles' => $roles,
                'permissions' => $permissions,
                'relationships' => $userData['relationships'] ?? [],
                'included' => $json['included'] ?? [],
            ];
        } catch (Throwable $e) {
            Log::error('Error fetching external user: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Validate an existing access token with API Gateway /auth/token/validate.
     */
    public function validateToken(string $token): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->accept('application/vnd.api+json, application/json')
                ->post("{$this->baseUrl}/auth/token/validate");

            return $response->successful() && ($response->json('meta.valid') === true);
        } catch (Throwable $e) {
            Log::error('Error validating token: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Revoke access token at API Gateway /auth/logout.
     */
    public function logout(string $token): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->accept('application/vnd.api+json, application/json')
                ->post("{$this->baseUrl}/auth/logout");

            return $response->successful();
        } catch (Throwable $e) {
            Log::error('Error during external logout: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Request 6-digit password reset code at API Gateway /auth/password/forgot.
     */
    public function sendPasswordResetCode(string $email): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->accept('application/vnd.api+json, application/json')
                ->post("{$this->baseUrl}/auth/password/forgot", [
                    'email' => $email,
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::error('Error sending password reset code: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Reset password using code at API Gateway /auth/password/reset.
     */
    public function resetPassword(string $email, string $code, string $password): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->accept('application/vnd.api+json, application/json')
                ->post("{$this->baseUrl}/auth/password/reset", [
                    'email' => $email,
                    'code' => $code,
                    'password' => $password,
                    'password_confirmation' => $password,
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::error('Error resetting password: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Extract role identifiers/values from JSON:API response structure.
     *
     * @param array<string, mixed> $json
     * @return array<string>
     */
    protected function extractRoles(array $json): array
    {
        $roles = [];

        // 1. Extract from 'included' resources (JSON:API standard compound document)
        if (! empty($json['included']) && is_array($json['included'])) {
            foreach ($json['included'] as $includedItem) {
                if (is_array($includedItem) && ($includedItem['type'] ?? null) === 'roles') {
                    $roleValue = $includedItem['attributes']['value']
                        ?? $includedItem['attributes']['name']
                        ?? $includedItem['attributes']['slug']
                        ?? $includedItem['id']
                        ?? null;

                    if ($roleValue) {
                        $roles[] = (string) $roleValue;
                    }
                }
            }
        }

        // 2. Extract from 'data.relationships.roles.data'
        if (empty($roles) && ! empty($json['data']['relationships']['roles']['data'])) {
            $rolesData = $json['data']['relationships']['roles']['data'];
            if (is_array($rolesData)) {
                foreach ($rolesData as $roleItem) {
                    if (is_array($roleItem)) {
                        $roleValue = $roleItem['value'] ?? $roleItem['name'] ?? $roleItem['id'] ?? null;
                        if ($roleValue) {
                            $roles[] = (string) $roleValue;
                        }
                    }
                }
            }
        }

        // 3. Fallback: check data.attributes.roles
        if (empty($roles) && ! empty($json['data']['attributes']['roles'])) {
            $attrRoles = $json['data']['attributes']['roles'];
            if (is_array($attrRoles)) {
                foreach ($attrRoles as $role) {
                    $roles[] = is_array($role) ? ($role['value'] ?? $role['name'] ?? '') : (string) $role;
                }
            } elseif (is_string($attrRoles)) {
                $roles[] = $attrRoles;
            }
        }

        return array_values(array_filter(array_unique($roles)));
    }

    /**
     * Extract permissions from JSON:API response structure.
     *
     * @param array<string, mixed> $json
     * @return array<string>
     */
    protected function extractPermissions(array $json): array
    {
        $permissions = $json['data']['attributes']['permissions'] ?? [];

        if (is_string($permissions)) {
            return [$permissions];
        }

        if (is_array($permissions)) {
            return array_map(fn ($p) => is_array($p) ? ($p['name'] ?? $p['value'] ?? '') : (string) $p, $permissions);
        }

        return [];
    }

    private function logWarning(string $message, string $email, array $context): void
    {
        if (config('services.smartbus.gateway.log_failures', true)) {
            Log::warning($message, array_merge(['email' => $email], $context));
        }
    }
}

