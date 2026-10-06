<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;

class Login extends BaseLogin
{
    public function mount(): void
    {
        parent::mount();

        $reason = session()->get('auth_notice');

        if ($reason === null) {
            return;
        }

        $message = match ($reason) {
            'session_ended' => __(
                'Your session is no longer valid. Please sign in again.'
            ),
            'token_missing' => __(
                'Your session could not be verified. Please sign in again.'
            ),
            default => __(
                'Your session ended unexpectedly. Please sign in again.'
            ),
        };

        Notification::make()
            ->title(__('Session Ended'))
            ->body($message)
            ->warning()
            ->persistent()
            ->send();
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response !== null) {
            session()->forget('auth_notice');
        }

        return $response;
    }
}
