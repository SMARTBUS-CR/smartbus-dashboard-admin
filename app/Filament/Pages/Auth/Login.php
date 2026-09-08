<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();

        if (! Filament::auth()->attempt([
            'email' => $data['email'],
            'password' => $data['password'],
        ], $data['remember'] ?? false)) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.failed'),
            ]);
        }

        $user = Filament::auth()->user();

        if (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Filament::auth()->logout();

            Notification::make()
                ->title(__('Acceso denegado'))
                ->body(__('Tu cuenta no cuenta con permisos administrativos para ingresar al panel.'))
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'data.email' => __('No tienes permisos para acceder al panel administrativo.'),
            ]);
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }
}
