<?php

namespace App\Auth;

use App\Models\User;
use App\Services\AuthService;
use App\Traits\ApiLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

class ExternalUserProvider implements UserProvider
{
    use ApiLogger;

    public function __construct(
        protected AuthService $authService,
        protected string $model = User::class
    ) {}

    /**
     * Get the model class name used by the provider.
     *
     * @return string The model class name.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Rehash the user's password if required and supported.
     *
     * @param  Authenticatable  $user  User instance to check for rehashing.
     * @param  array  $credentials  Credentials to validate against the user.
     * @param  bool  $force  Whether to force rehashing regardless of the current hash state.
     * @return bool True if the password was rehashed, false otherwise.
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): bool
    {
        return false;
    }

    /**
     * Retrieve a user by the given credentials.
     *
     * @param  array  $credentials  Credentials to use for the search.
     * @return Authenticatable|null The user instance if found, null otherwise.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        if (! $email) {
            return null;
        }

        return User::on('mysql')
            ->where('email', $email)
            ->first();
    }

    /**
     * Retrieve a user by their unique identifier.
     *
     * @param  mixed  $identifier  The unique identifier of the user.
     * @return Authenticatable|null The user instance if found, null otherwise.
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        return User::on('mysql')->find($identifier);
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     *
     * @param  mixed  $identifier  The user's unique identifier.
     * @param  string  $token  The "remember me" token associated with the user.
     * @return Authenticatable|null The user instance if found, null otherwise.
     */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    /**
     * Update the "remember me" token for the given user in storage.
     *
     * @param  string  $token  The new "remember me" token to be set for the user.
     */
    public function updateRememberToken(Authenticatable $user, $token): void
    { /** Not used with external session authentication **/
    }

    /**
     * Validate a user against the given credentials.
     *
     * @param  Authenticatable  $user  User instance to validate.
     * @param  array  $credentials  Credentials to validate against the user.
     * @return bool True if the credentials are valid, false otherwise.
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;
        if (! $email || ! $password) {
            return false;
        }

        $authData = $this->authService->authenticate($email, $password);
        if (! $authData) {
            return false;
        }

        $this->log('debug', 'External authentication successful for user: '.$email, [
            'user_id' => $user->getAuthIdentifier(),
            'external_auth_data' => $authData,
        ]);

        $externalUser = $authData['user'] ?? null;

        session([
            'external_auth_token' => $authData['access_token'] ?? null,
            'external_user_data' => $externalUser,
            'user_id' => $user->getAuthIdentifier(),
            'user_email' => $externalUser['attributes']['email'] ?? null,
            'user_name' => $externalUser['attributes']['name'] ?? null,
            'user_roles' => $externalUser['roles'] ?? [],
            'user_permissions' => $externalUser['permissions'] ?? [],
        ]);

        return true;
    }
}
