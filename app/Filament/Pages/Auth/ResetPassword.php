<?php

namespace App\Filament\Pages\Auth;

use App\Services\ExternalAuthService;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class ResetPassword extends BaseResetPassword
{
    public ?string $code = null;

    /**
     * !! REVISAR, No funciona correctamente !!
     *
     * @return array<int, TextInput>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        TextInput::make('code')
                            ->label(__('Código de Verificación (6 dígitos)'))
                            ->required()
                            ->length(6)
                            ->numeric()
                            ->autofocus(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        $data = $this->form->getState();

        $authService = app(ExternalAuthService::class);
        $success = $authService->resetPassword(
            $data['email'] ?? $this->email,
            $data['code'],
            $data['password']
        );

        if (! $success) {
            throw ValidationException::withMessages([
                'data.code' => __('El código es inválido o ha expirado. Por favor solicita uno nuevo.'),
            ]);
        }

        Notification::make()
            ->title(__('Contraseña restablecida'))
            ->body(__('Tu contraseña ha sido actualizada exitosamente. Ya puedes iniciar sesión.'))
            ->success()
            ->send();

        return app(PasswordResetResponse::class);
    }
}
