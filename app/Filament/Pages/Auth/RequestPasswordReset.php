<?php

namespace App\Filament\Pages\Auth;

use App\Services\ExternalAuthService;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        $data = $this->form->getState();

        $authService = app(ExternalAuthService::class);
        $success = $authService->sendPasswordResetCode($data['email']);

        if (! $success) {
            throw ValidationException::withMessages([
                'email' => __('No se pudo enviar el código de restablecimiento. Verifica el correo e intenta nuevamente.'),
            ]);
        }

        Notification::make()
            ->title(__('Código de restablecimiento enviado'))
            ->body(__('Hemos enviado un código de 6 dígitos a tu correo electrónico. Es válido por 15 minutos.'))
            ->success()
            ->send();
    }
}
