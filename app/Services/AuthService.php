<?php

namespace App\Services;

use App\Enums\TokenValidationStatus;
use App\Traits\ApiLogger;
use App\Traits\HasHttpRequests;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

use function is_scalar;
use function is_string;

class AuthService
{
    use ApiLogger, HasHttpRequests;

    protected readonly string $baseUrl;

    protected readonly int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.smartbus.gateway.url'), '/');
        $this->timeout = (int) config('services.smartbus.gateway.timeout', 30);
    }

    /**
     * Authenticate credentials against API Gateway /auth/login.
     *
     * @param  string  $email  The user's email address
     * @param  string  $password  The user's password
     * @return array{access_token: string, token_type: string, expires_at: ?string, user: array<string, mixed>}|null
     */
    public function authenticate(string $email, string $password): ?array
    {
        try {
            $response = $this->sendHttpRequest(
                method: 'POST',
                endpoint: '/auth/login',
                data: ['email' => $email, 'password' => $password],
                queryParams: ['include' => 'roles']
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    message: 'Authentication request failed: '.$response->body(),
                    code: $response->status(),
                );
            }

            $json = $response->json() ?? [];
            $token = $json['meta']['access_token'] ?? null;
            $userData = $json['data'] ?? null;

            if (! $token || ! $userData) {
                $this->log('error', 'Authentication response missing token or user data', [
                    'response' => $json,
                    'email' => $email,
                ]);

                return null;
            }

            return [
                'access_token' => $token,
                'token_type' => $json['meta']['token_type'] ?? 'Bearer',
                'expires_at' => $json['meta']['expires_at'] ?? null,
                'user' => [
                    'id' => $userData['id'] ?? null,
                    'type' => $userData['type'] ?? 'users',
                    ...$userData['attributes'] ?? [],
                    'roles' => $this->extractRoles($json),
                    'permissions' => $this->extractPermissions($json),
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Authentication request failed', [
                'exception' => $e->getMessage(),
                'status' => $e instanceof RuntimeException ? $e->getCode() : null,
                'email' => $email,
            ]);

            return null;
        }
    }

    /**
     * Get authenticated user info from API Gateway /auth/user.
     *
     * @param  string  $token  The access token to use for authentication
     * @return array<string, mixed>|null The user data or null if not found
     */
    public function getUser(string $token): ?array
    {
        try {
            $response = $this->sendHttpRequest(
                method: 'GET',
                endpoint: '/auth/user',
                queryParams: ['include' => 'roles'],
                token: $token
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    message: 'User info request failed: '.$response->body(),
                    code: $response->status(),
                );
            }

            $json = $response->json();
            $userData = $json['data'] ?? null;

            if (! $userData) {
                $this->log('error', 'User info response missing user data', [
                    'response' => $json,
                    'token' => $token,
                ]);

                return null;
            }

            return [
                'id' => $userData['id'] ?? null,
                'type' => $userData['type'] ?? 'users',
                ...$userData['attributes'] ?? [],
                'roles' => $this->extractRoles($json),
                'permissions' => $this->extractPermissions($json),
            ];
        } catch (Throwable $e) {
            $this->log('error', 'User info request failed', [
                'exception' => $e->getMessage(),
                'status' => $e instanceof RuntimeException ? $e->getCode() : null,
                'token' => $token,
            ]);

            return null;
        }
    }

    /**
     * Revoke access token at API Gateway /auth/logout.
     *
     * @param  string  $token  The access token to revoke
     * @return bool True if the logout was successful, false otherwise
     */
    public function logout(string $token): bool
    {
        try {
            $response = $this->sendHttpRequest(
                method: 'POST',
                endpoint: '/auth/logout',
                token: $token
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    message: 'Logout request failed: '.$response->body(),
                    code: $response->status(),
                );
            }

            return true;
        } catch (Throwable $e) {
            $this->log('error', 'Logout request failed', [
                'exception' => $e->getMessage(),
                'status' => $e instanceof RuntimeException ? $e->getCode() : null,
                'token' => $token,
            ]);

            return false;
        }
    }

    /**
     * Request 6-digit password reset code at API Gateway /auth/password/forgot.
     *
     * @param  string  $email  The user's email address
     * @return array{success: bool, message: string, errors: array<string, array<int, string>>}
     */
    public function sendPasswordResetCode(string $email): array
    {
        try {
            $response = $this->sendHttpRequest(
                method: 'POST',
                endpoint: '/auth/password/forgot',
                data: ['email' => $email]
            );

            $this->log('debug', 'Password reset request sent', [
                'email' => $email,
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);

            $payload = $response->json() ?? [];

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => $this->extractGatewayMessage($payload, 'Password reset request failed.'),
                    'errors' => $this->extractGatewayErrors($payload),
                ];
            }

            return [
                'success' => true,
                'message' => $this->extractGatewayMessage($payload, 'Password reset code sent successfully.'),
                'errors' => [],
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Password reset request failed', [
                'exception' => $e->getMessage(),
                'email' => $email,
            ]);

            return [
                'success' => false,
                'message' => 'Password reset request failed due to an internal error.',
                'errors' => [],
            ];
        }
    }

    /**
     * Reset password using code at API Gateway /auth/password/reset.
     *
     * @param  string  $email  The user's email address
     * @param  string  $code  The 6-digit reset code
     * @param  string  $password  The new password
     * @return array{success: bool, message: string, errors: array<string, array<int, string>>}
     */
    public function resetPassword(string $email, string $code, string $password): array
    {
        try {
            $response = $this->sendHttpRequest(
                method: 'POST',
                endpoint: '/auth/password/reset',
                data: [
                    'email' => $email,
                    'code' => $code,
                    'password' => $password,
                    'password_confirmation' => $password,
                ]
            );

            $this->log('debug', 'Password reset request sent', [
                'email' => $email,
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);

            $payload = $response->json() ?? [];

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => $this->extractGatewayMessage($payload, 'Password reset request failed.'),
                    'errors' => $this->extractGatewayErrors($payload),
                ];
            }

            return [
                'success' => true,
                'message' => $this->extractGatewayMessage($payload, 'Password reset successfully.'),
                'errors' => [],
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Password reset request failed', [
                'exception' => $e->getMessage(),
                'email' => $email,
            ]);

            return [
                'success' => false,
                'message' => 'Password reset request failed due to an internal error.',
                'errors' => [],
            ];
        }
    }

    /**
     * Validate an existing access token with API Gateway /auth/token/validate.
     *
     * @param  string  $token  The access token to validate
     * @return bool True if the token is valid, false otherwise
     */
    // public function validateToken(string $token): bool
    // {
    //     try {
    //         $response = $this->sendHttpRequest(
    //             method: 'POST',
    //             endpoint: '/auth/token/validate',
    //             token: $token
    //         );

    //         if (! $response->successful()) {
    //             throw new RuntimeException(
    //                 message: 'Token validation request failed: '.$response->body(),
    //                 code: $response->status(),
    //             );
    //         }

    //         return $response->json('meta.valid') === true;
    //     } catch (Throwable $e) {
    //         $this->log('error', 'Token validation failed', [
    //             'exception' => $e->getMessage(),
    //             'token' => $token,
    //         ]);

    //         return false;
    //     }
    // }

    public function validateToken(string $token): TokenValidationStatus
    {
        if (trim($token) === '') {
            return TokenValidationStatus::Invalid;
        }

        try {
            $response = $this->sendHttpRequest(
                method: 'POST',
                endpoint: '/auth/token/validate',
                token: $token,
            );
        } catch (ConnectionException $ce) {
            $this->log('warning', 'Token validation service is unreachable.', [
                'exception' => $ce->getMessage(),
            ]);

            return TokenValidationStatus::Unavailable;
        }

        if ($response->unauthorized()) {
            return TokenValidationStatus::Invalid;
        }

        if ($response->forbidden()) {
            return TokenValidationStatus::Forbidden;
        }

        if ($response->ok()) {
            $valid = $response->json('meta.valid');
            if ($valid === true) {
                return TokenValidationStatus::Valid;
            }
            if ($valid === false) {
                return TokenValidationStatus::Invalid;
            }
        }

        $this->log('warning', 'Unexpected token validation response.', [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ]);

        return TokenValidationStatus::Unavailable;
    }

    /**
     * Extract role identifiers/values from JSON:API payload.
     *
     * @param  array<string, mixed>  $json
     * @return array<string>
     */
    protected function extractRoles(array $json): array
    {
        $roles = collect(data_get($json, 'included', []))
            ->where('type', 'roles')
            ->map(fn ($item) => data_get($item, 'attributes.value')
                ?? data_get($item, 'attributes.name')
                ?? data_get($item, 'id')
            )
            ->filter();

        if ($roles->isEmpty()) {
            $roles = collect(data_get($json, 'data.relationships.roles.data', []))
                ->map(fn ($item) => data_get($item, 'value') ?? data_get($item, 'id'))
                ->filter();
        }

        return $roles->map(fn ($role) => (string) $role)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Extract permissions from JSON:API response structure.
     *
     * @param  array<string, mixed>  $json
     * @return array<string>
     */
    protected function extractPermissions(array $json): array
    {
        $permissions = data_get($json, 'data.attributes.permissions', []);

        return collect(is_array($permissions) ? $permissions : [$permissions])
            ->map(fn ($permission) => is_array($permission)
                ? (data_get($permission, 'name') ?? data_get($permission, 'value'))
                : $permission
            )
            ->filter()
            ->map(fn ($permission) => (string) $permission)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Extract and normalize errors from API Gateway response payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array<int, string>>
     */
    protected function extractGatewayErrors(array $payload): array
    {
        $errors = data_get($payload, 'errors');
        if (! is_array($errors)) {
            return [];
        }

        return collect($errors)
            ->mapWithKeys(function ($value, $key) {
                $messages = match (true) {
                    is_array($value) => array_map(static fn ($err) => (string) $err, $value),
                    is_scalar($value) => [(string) $value],
                    default => [],
                };

                return [(string) $key => $messages];
            })
            ->filter()
            ->all();
    }

    /**
     * Extract a user-friendly message from the API Gateway response payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function extractGatewayMessage(array $payload, string $fallback): string
    {
        $message = data_get($payload, 'meta.message') ?? data_get($payload, 'message');

        return is_string($message) && filled($message)
            ? $message
            : $fallback;
    }
}
