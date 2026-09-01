<?php

namespace App\Filament\Pages\Auth;

use App\Services\ExternalAuthService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();

        $externalAuthService = app(ExternalAuthService::class);
        $authResult = $externalAuthService->authenticate(
            $data['email'],
            $data['password']
        );

        if (! $authResult) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.failed'),
            ]);
        }

        $userData = $authResult['data'];

        // Store the external auth token and user data in the session
        session([
            'external_auth_token' => $authResult['token'],
            'external_user_data' => $userData,
            'user_id' => $userData['id'] ?? null,
            'user_email' => $userData['email'] ?? null,
            'user_name' => $userData['name'] ?? null,
            'user_role' => $userData['role'] ?? null,
        ]);

        session()->regenerate();

        return app(LoginResponse::class);
    }
}