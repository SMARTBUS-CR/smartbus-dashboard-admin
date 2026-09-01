<?php

namespace App\Auth;

use App\Services\ExternalAuthService;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

class SessionGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        protected Request $request,
        protected Session $session
    ) {}

    public function user()
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $userData = $this->session->get('external_user_data');

        if ($userData && is_array($userData)) {
            $this->user = new SessionUser($userData);
        }

        return $this->user;
    }

    public function validate(array $credentials = []): bool
    {
        return $this->session->has('external_auth_token')
            && $this->session->has('external_user_data');
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id()
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function setUser($user)
    {
        $this->user = $user;

        return $this;
    }

    public function logout(): void
    {
        $token = $this->session->get('external_auth_token');

        if ($token) {
            try {
                app(ExternalAuthService::class)->logout($token);
            } catch (\Throwable) {
                // Ignore API connection failures during local logout
            }
        }

        $this->session->forget([
            'external_auth_token',
            'external_user_data',
            'user_id',
            'user_email',
            'user_name',
            'user_roles',
        ]);

        $this->session->invalidate();
        $this->session->regenerateToken();

        $this->user = null;
    }
}
