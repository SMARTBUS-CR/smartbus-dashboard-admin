<?php

namespace App\Filament\Pages\Auth;

use App\Services\ExternalAuthService;
use App\Traits\ApiLogger;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class ResetPassword extends BaseResetPassword
{
    use ApiLogger;

    public ?string $code = null;

    /**
     * Define the form schema for the password reset process.
     *
     * @return array Form components for the password reset form.
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        TextInput::make('code')
                            ->label(__('Código de verificación (6 dígitos)'))
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

    /**
     * Handle the password reset process.
     *
     * @return PasswordResetResponse Response indicating the result of the password reset attempt.
     */
    public function resetPassword(): ?PasswordResetResponse
    {
        $data = $this->form->getState();

        $authService = app(ExternalAuthService::class);
        $result = $authService->resetPassword(
            $data['email'] ?? $this->email,
            (string) ($data['code'] ?? ''),
            (string) ($data['password'] ?? '')
        );

        if (! $result['success']) {
            $this->log('error', $result['message'], [
                'email' => $data['email'] ?? $this->email,
                'code' => $data['code'] ?? null,
                'errors' => $result['errors'] ?? null,
            ]);
            throw ValidationException::withMessages($this->normalizeGatewayErrors($result['errors'], $result['message']));
        }

        Notification::make()
            ->title(__('Contraseña restablecida'))
            ->body($result['message'])
            ->success()
            ->send();

        return app(PasswordResetResponse::class);
    }

    /**
     * Normalize the errors returned from the external authentication service.
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    protected function normalizeGatewayErrors(array $errors, string $fallback): array
    {
        $this->log('debug', 'Normalizing gateway errors', [
            'errors' => $errors,
            'fallback' => $fallback,
        ]);

        if ($errors !== []) {
            return $errors;
        }

        return [
            'data.code' => [$fallback],
        ];
    }
}
