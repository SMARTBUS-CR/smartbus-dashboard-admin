<?php

namespace App\Filament\Pages\Auth;

use App\Enums\UserRole;
use App\Services\ExternalAuthService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
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

        $userData = $authResult['user'] ?? [];
        $roles = $userData['roles'] ?? [];

        Log::info('External API authentication successful', [
            'user_id' => $userData['id'] ?? null,
            'email' => $userData['email'] ?? null,
            'roles' => $roles,
        ]);

        // Strict role validation: only super-admin and company-admin allowed
        $allowedRoles = UserRole::adminRoles();

        $hasAccess = count(array_intersect($allowedRoles, $roles)) > 0;

        if (! $hasAccess) {
            // Revoke generated token on API Gateway
            if (! empty($authResult['access_token'])) {
                $externalAuthService->logout($authResult['access_token']);
            }

            Notification::make()
                ->title(__('Acceso denegado'))
                ->body(__('Tu cuenta no cuenta con permisos administrativos para ingresar al panel.'))
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'data.email' => __('No tienes permisos para acceder al panel administrativo.'),
            ]);
        }

        // Store external auth token and user data in session
        session([
            'external_auth_token' => $authResult['access_token'],
            'external_user_data' => $userData,
            'user_id' => $userData['id'] ?? null,
            'user_email' => $userData['email'] ?? null,
            'user_name' => $userData['name'] ?? null,
            'user_roles' => $roles,
        ]);

        session()->regenerate();

        return app(LoginResponse::class);
    }
}
