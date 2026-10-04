<?php

namespace App\Auth;

use App\Models\User;
use App\Services\AuthService;
use App\Traits\ApiLogger;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

class SessionGuard implements StatefulGuard
{
    use ApiLogger, GuardHelpers;

    protected bool $viaRemember = false;

    public function __construct(
        public string $name,
        UserProvider $provider,
        protected Session $session,
        protected ?Request $request = null
    ) {
        $this->provider = $provider;
    }

    /**
     * Attempt to authenticate a user using the given credentials and callbacks.
     *
     * @param  array  $credentials  The credentials to validate.
     * @param  array|callable|null  $callbacks  Optional callbacks to execute after successful authentication.
     * @param  bool  $remember  Whether to remember the user for future sessions.
     * @return bool True if authentication was successful, false otherwise.
     */
    public function attemptWhen(array $credentials = [], array|callable|null $callbacks = null, bool $remember = false): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);
        if (! $user || ! $this->provider->validateCredentials($user, $credentials)) {
            return false;
        }

        if (is_callable($callbacks) && ! $callbacks($user)) {
            return false;
        }
        $this->login($user, $remember);

        return true;
    }

    /**
     * Attempt to authenticate a user using the given credentials.
     *
     * @param  array  $credentials  The credentials to validate.
     * @param  bool  $remember  Whether to remember the user for future sessions.
     * @return bool True if authentication was successful, false otherwise.
     */
    public function attempt(array $credentials = [], $remember = false): bool
    {
        return $this->attemptWhen($credentials, null, (bool) $remember);
    }

    /**
     * Log a user into the application.
     *
     * @param  Authenticatable  $user  The user instance to log in.
     * @param  bool  $remember  Whether to remember the user for future sessions.
     */
    public function login(Authenticatable $user, $remember = false): void
    {
        $this->session->put($this->getName(), $user->getAuthIdentifier());
        $this->session->put('user_id', $user->getAuthIdentifier());
        $this->setUser($user);
    }

    /**
     * Log the given user ID into the application.
     *
     * @param  mixed  $id  The unique identifier of the user to log in.
     * @param  bool  $remember  Whether to remember the user for future sessions.
     * @return Authenticatable|false The user instance if login was successful, false otherwise.
     */
    public function loginUsingId($id, $remember = false): Authenticatable|bool
    {
        $user = $this->provider->retrieveById($id);
        if (! $user) {
            return false;
        }

        $this->login($user, $remember);

        return $user;
    }

    /**
     * {@inheritDoc}
     */
    public function logout(): void
    {
        $token = $this->session->get('external_auth_token');
        if ($token) {
            $isLoggedOut = app(AuthService::class)->logout($token);
            if (! $isLoggedOut) {
                $this->log(
                    'warning',
                    'Failed to log out from external authentication service.',
                );
            }
        }

        $this->session->forget([
            $this->getName(),
            'external_auth_token',
            'external_user_data',
            'user_id',
            'user_email',
            'user_name',
            'user_roles',
            'user_permissions',
        ]);

        $this->session->invalidate();
        $this->session->regenerateToken();

        $this->user = null;
    }

    /**
     * Log a user into the application without sessions or cookies.
     *
     * @param  array  $credentials  The credentials to validate.
     * @return bool True if authentication was successful, false otherwise.
     */
    public function once(array $credentials = []): bool
    {
        $credentialsAreValid = $this->validate($credentials);
        if (! $credentialsAreValid) {
            return false;
        }

        $user = $this->provider->retrieveByCredentials($credentials);
        $this->setUser($user);

        return true;
    }

    /**
     * Log the given user ID into the application without sessions or cookies.
     *
     * @param  mixed  $id  The unique identifier of the user to log in.
     * @return Authenticatable|false The user instance if login was successful, false otherwise
     */
    public function onceUsingId($id): Authenticatable|bool
    {
        $user = $this->provider->retrieveById($id);
        if (! $user) {
            return false;
        }

        $this->setUser($user);

        return $user;
    }

    /**
     * Determine if the user was authenticated via "remember me" cookie.
     *
     * @return bool True if the user was authenticated via "remember me" cookie, false otherwise.
     */
    public function viaRemember(): bool
    {
        return $this->viaRemember;
    }

    /**
     * Get the currently authenticated user.
     *
     * If the user is not already set, it will attempt to retrieve the user from the
     * session or external authentication service.
     *
     * @return Authenticatable|null The authenticated user instance if available, null otherwise.
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        // Try to retrieve the user from the ID stored in the session
        $id = $this->session->get($this->getName()) ?? $this->session->get('user_id');
        if ($id !== null) {
            $this->user = $this->provider->retrieveById($id);
        }

        // Fallback to retrieving the user from the authentication service if not found in the session
        if ($this->user === null) {
            $token = $this->session->get('external_auth_token');

            if ($token) {
                $userData = app(AuthService::class)->getUser($token);

                if ($userData) {
                    // Try to retrieve the user from the database using the ID from the external service
                    if (isset($userData['id'])) {
                        $this->user = $this->provider->retrieveById($userData['id']);
                    }

                    // If the user is not found in the database, create a new User instance with the external data
                    if (! $this->user) {
                        $this->user = (new User)->forceFill([
                            'id' => $userData['id'] ?? null,
                            'name' => $userData['name'] ?? null,
                            'email' => $userData['email'] ?? null,
                        ]);
                    }

                    // Re-store / update the session with the user data from the external service
                    if ($this->user) {
                        $this->session->put($this->getName(), $this->user->getAuthIdentifier());
                        $this->session->put('user_id', $this->user->getAuthIdentifier());
                        $this->session->put('external_user_data', $userData);
                        $this->session->put('user_email', $userData['email'] ?? null);
                        $this->session->put('user_name', $userData['name'] ?? null);
                        $this->session->put('user_roles', $userData['roles'] ?? []);
                        $this->session->put('user_permissions', $userData['permissions'] ?? []);
                    }
                }
            }
        }

        return $this->user;
    }

    /**
     * Validate a user's credentials.
     *
     * @param  array  $credentials  The credentials to validate.
     * @return bool True if the credentials are valid, false otherwise.
     */
    public function validate(array $credentials = []): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        return $user && $this->provider->validateCredentials($user, $credentials);
    }

    /**
     * Get a unique identifier for the auth session value.
     *
     * @return string A unique identifier for the auth session value.
     */
    public function getName(): string
    {
        return "login_{$this->name}_".sha1(static::class);
    }

    /**
     * Hash the user's password for cookie storage.
     *
     * @return string The hashed password.
     */
    public function hashPasswordForCookie(): string
    {
        $user = $this->user();
        if (! $user) {
            return '';
        }

        $password = $user->getAuthPassword();
        if (! empty($password)) {
            return sha1($password);
        }

        return sha1($user->getKey().'|'.$this->session->get('user_email', ''));
    }

    /**
     * Log the user out of the application on the current device.
     */
    public function logoutCurrentDevice(): void
    {
        $this->logout();
    }

    /**
     * Log the user out of the application on other devices.
     *
     * @param  string  $password
     */
    public function logoutOtherDevices($password): ?bool
    {
        return true;
    }
}
