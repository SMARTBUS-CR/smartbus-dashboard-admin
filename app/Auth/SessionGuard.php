<?php

namespace App\Auth;

use App\Models\User;
use App\Services\ExternalAuthService;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use stdClass;

class SessionGuard implements StatefulGuard
{
    use GuardHelpers;

    /**
     * Indicates if the user was authenticated via a remember me cookie.
     *
     * @var bool Remember me cookie flag
     */
    protected bool $viaRemember = false;

    /**
     * Indicates if the user's password has been hashed for cookie storage.
     *
     * @var bool Hashed password for cookie storage
     */
    protected bool $hasPasswordForCookie = false;

    public function __construct(
        protected Request $request,
        protected Session $session
    ) {}

    /**
     * Attempt to authenticate a user using the provided credentials.
     *
     * @param  array  $credentials  User credentials for authentication
     * @param  mixed  $remember  Whether to remember the user for future sessions
     * @return bool True if authentication was successful, false otherwise
     */
    public function attempt(array $credentials = [], $remember = false): bool
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! $email || ! $password) {
            return false;
        }

        $authService = app(ExternalAuthService::class);
        $authData = $authService->authenticate($email, $password);

        if (! $authData) {
            return false;
        }

        $externalUser = $authData['user'] ?? [];
        $userId = $externalUser['id'] ?? null;

        if (! $userId) {
            return false;
        }

        $user = User::on('mysql')->find($userId);

        if (! $user) {
            return false;
        }

        $this->session->put('external_auth_token', $authData['access_token']);
        $this->session->put('external_user_data', $externalUser);
        $this->session->put('user_id', $user->getKey());
        $this->session->put('user_email', $user->email);
        $this->session->put('user_name', $user->name);
        $this->session->put('user_roles', $externalUser['roles'] ?? []);
        $this->session->put('user_permissions', $externalUser['permissions'] ?? []);

        $this->setUser($user);

        return true;
    }

    /**
     * Validate the user's credentials without logging them in.
     *
     * @param  array  $credentials  User credentials for validation
     * @return bool True if credentials are valid, false otherwise
     */
    public function validate(array $credentials = []): bool
    {
        return $this->attempt($credentials);
    }

    /**
     * Get the currently authenticated user.
     *
     * @return Authenticatable|null The authenticated user or null if not authenticated
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $userId = $this->session->get('user_id');

        if ($userId) {
            $this->user = User::on('mysql')->find($userId);
        }

        return $this->user;
    }

    /**
     * Log the given user into the application.
     *
     * @param  mixed  $remember
     */
    public function login(Authenticatable $user, $remember = false): void
    {
        $this->session->put('user_id', $user->getAuthIdentifier());
        $this->setUser($user);
    }

    /**
     * Log the user out of the application.
     *
     * @param  string  $id  The ID of the user to log in
     * @return bool|Collection|stdClass|User The authenticated user or false if not found
     */
    public function loginUsingId($id, $remember = false): bool|Collection|stdClass|User
    {
        $user = User::on('mysql')->find($id);
        if ($user) {
            $this->login($user, $remember);

            return $user;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function once(array $credentials = []): bool
    {
        return $this->validate($credentials);
    }

    /**
     * {@inheritDoc}
     */
    public function onceUsingId($id)
    {
        return $this->loginUsingId($id);
    }

    /**
     * Determine if the user was authenticated via a remember me cookie.
     *
     * @return bool True if authenticated via remember me cookie, false otherwise
     */
    public function viaRemember(): bool
    {
        return $this->viaRemember;
    }

    /**
     * Hash the user's password for cookie storage.
     *
     * @return string The hashed password for cookie storage
     */
    public function hashPasswordForCookie(): string
    {
        $user = $this->user();

        if (! $user) {
            return '';
        }

        // Use the password stored in the database if available
        $password = $user->getAuthPassword();

        if (! empty($password)) {
            return sha1($password);
        }

        // Fallback to using the user's ID and email from the session if password is not available
        return sha1($user->getKey().'|'.$this->session->get('user_email', ''));
    }

    /**
     * Log the user out of the application and clear session data.
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
