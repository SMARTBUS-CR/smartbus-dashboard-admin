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
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    protected function normalizeGatewayErrors(array $errors, string $fallback): array
    {
        if ($errors !== []) {
            return $errors;
        }

        return [
            'data.code' => [$fallback],
        ];
    }
}
