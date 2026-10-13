<?php

namespace App\Filament\Auth;

use App\Exceptions\AuthenticationServiceException;
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
            'account_deactivated' => __(
                'Your account has been deactivated. Contact a system admin for assistance.'
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
        try {
            $response = parent::authenticate();
        } catch (AuthenticationServiceException $exception) {
            Notification::make()->title(__('Sign In Failed'))->body(__($exception->getMessage()))
                ->danger()->persistent()->send();

            return null;
        }

        if ($response !== null) {
            session()->forget('auth_notice');
        }

        return $response;
    }
}
