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
        $this->baseUrl = rtrim((string) config('services.smartbus.gateway.url'), '/');
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
                $this->log('warning', 'External API authentication failed', $email, [
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
            $this->log('error', 'External API authentication error', $email, ['error' => $e->getMessage()]);

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
            $this->log('error', 'Error fetching external user: '.$e->getMessage(), null, []);

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
            $this->log('error', 'Error validating token: '.$e->getMessage(), null, []);

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
            
            $this->log('info', 'External logout request sent', null, [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            $this->log('error', 'Error during external logout: '.$e->getMessage(), null, []);

            return false;
        }
    }

    /**
     * Request 6-digit password reset code at API Gateway /auth/password/forgot.
     *
     * @return array{success: bool, message: string, errors: array<string, array<int, string>>}
     */
    public function sendPasswordResetCode(string $email): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->accept('application/vnd.api+json, application/json')
                ->post("{$this->baseUrl}/auth/password/forgot", [
                    'email' => $email,
                ]);

            $this->log('info', 'Password reset code sent', null, [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);

            $payload = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => $this->extractGatewayMessage($payload, 'Hemos enviado un código de 6 dígitos a tu correo electrónico.'),
                    'errors' => [],
                ];
            }

            return [
                'success' => false,
                'message' => $this->extractGatewayMessage($payload, 'No se pudo enviar el código de restablecimiento.'),
                'errors' => $this->extractGatewayErrors($payload),
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Error sending password reset code: '.$e->getMessage(), null, []);

            return [
                'success' => false,
                'message' => 'No se pudo enviar el código de restablecimiento. Intenta nuevamente.',
                'errors' => [],
            ];
        }
    }

    /**
     * Reset password using code at API Gateway /auth/password/reset.
     *
     * @return array{success: bool, message: string, errors: array<string, array<int, string>>}
     */
    public function resetPassword(string $email, string $code, string $password): array
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

            $this->log('info', 'Response from password reset', null, [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);

            $payload = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => $this->extractGatewayMessage($payload, 'Tu contraseña ha sido actualizada exitosamente.'),
                    'errors' => [],
                ];
            }

            return [
                'success' => false,
                'message' => $this->extractGatewayMessage($payload, 'El código es inválido o ha expirado.'),
                'errors' => $this->extractGatewayErrors($payload),
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Error resetting password: '.$e->getMessage(), null, []);

            return [
                'success' => false,
                'message' => 'No se pudo restablecer la contraseña. Intenta nuevamente.',
                'errors' => [],
            ];
        }
    }

    /**
     * Extract role identifiers/values from JSON:API response structure.
     *
     * @param  array<string, mixed>  $json
     * @return array<string>
     */
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<int, string>>
     */
    protected function extractGatewayErrors(array $payload): array
    {
        $errors = $payload['errors'] ?? [];

        if (! is_array($errors)) {
            return [];
        }

        $normalized = [];

        foreach ($errors as $key => $value) {
            if (is_array($value)) {
                $normalized[(string) $key] = array_map(static fn ($error) => (string) $error, $value);
            } elseif (is_string($value)) {
                $normalized[(string) $key] = [(string) $value];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractGatewayMessage(array $payload, string $fallback): string
    {
        if (isset($payload['meta']['message']) && is_string($payload['meta']['message'])) {
            return $payload['meta']['message'];
        }

        if (isset($payload['message']) && is_string($payload['message'])) {
            return $payload['message'];
        }

        return $fallback;
    }

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
     * @param  array<string, mixed>  $json
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

    private function log(string $type, string $message, ?string $email = null, array $context): void
    {
        if (config('services.smartbus.gateway.log_failures', true)) {
            match ($type) {
                'warning' => Log::warning($message, ['email' => $email, ...$context]),
                'error' => Log::error($message, ['email' => $email, ...$context]),
                default => Log::info($message, ['email' => $email, ...$context]),
            };
        }
    }
}
