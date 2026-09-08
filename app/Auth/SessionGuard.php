<?php

namespace App\Auth;

use App\Services\ExternalAuthService;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

class SessionGuard implements StatefulGuard
{
    use GuardHelpers;

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
    public function loginUsingId($id, $remember = false)
    {
        $user = $this->provider->retrieveById($id);

        if ($user) {
            $this->login($user, $remember);

            return $user;
        }

        return false;
    }

    /**
     * Log a user into the application without sessions or cookies.
     *
     * @param  array  $credentials  The credentials to validate.
     * @return bool True if authentication was successful, false otherwise.
     */
    public function once(array $credentials = []): bool
    {
        if ($this->validate($credentials)) {
            $this->setUser($this->provider->retrieveByCredentials($credentials));

            return true;
        }

        return false;
    }

    /**
     * Log the given user ID into the application without sessions or cookies.
     *
     * @param  mixed  $id  The unique identifier of the user to log in.
     * @return Authenticatable|false The user instance if login was successful, false otherwise
     */
    public function onceUsingId($id)
    {
        $user = $this->provider->retrieveById($id);

        if ($user) {
            $this->setUser($user);

            return $user;
        }

        return false;
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
     * @return Authenticatable|null The authenticated user instance if available, null otherwise.
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $id = $this->session->get($this->getName()) ?? $this->session->get('user_id');

        if ($id !== null) {
            $this->user = $this->provider->retrieveById($id);
        }

        return $this->user;
    }

    /**
     * Get a unique identifier for the auth session value.
     *
     * @return string A unique identifier for the auth session value.
     */
    public function getName(): string
    {
        return 'login_'.$this->name.'_'.sha1(static::class);
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
     * Log the user out of the application.
     */
    public function logout(): void
    {
        $token = $this->session->get('external_auth_token');

        if ($token) {
            try {
                app(ExternalAuthService::class)->logout($token);
            } catch (\Throwable) {
                // Silently ignore connection errors on logout
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
}
