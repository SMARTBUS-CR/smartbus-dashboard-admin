<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    /**
     * Handle the authentication process for the user.
     *
     * @return LoginResponse Response indicating the result of the authentication attempt.
     */
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $authenticated = Filament::auth()->attempt(
            [
                'email' => $data['email'],
                'password' => $data['password'],
            ],
            $data['remember'] ?? false
        );

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.failed'),
            ]);
        }

        $user = Filament::auth()->user();

        if (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Filament::auth()->logout();

            Notification::make()
                ->title(__('auth.login.errors.title'))
                ->body(__('auth.login.errors.message'))
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'data.email' => __('auth.login.errors.field_message'),
            ]);
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }
}
