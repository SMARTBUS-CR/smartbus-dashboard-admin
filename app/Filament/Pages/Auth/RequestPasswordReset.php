<?php

namespace App\Filament\Pages\Auth;

use App\Services\ExternalAuthService;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    public bool $showResetForm = false;

    public ?string $email = null;

    public ?string $code = null;

    public ?string $password = null;

    public ?string $passwordConfirmation = null;

    public function request(): void
    {
        $data = $this->form->getState();

        $authService = app(ExternalAuthService::class);
        $result = $authService->sendPasswordResetCode($data['email']);

        if (! $result['success']) {
            $errors = $this->normalizeGatewayErrors($result['errors'], $result['message']);
            Notification::make()
                ->title($errors['email'][0] ?? '')
                ->body(__('passwords.user'))
                ->danger()
                ->send();
            throw ValidationException::withMessages($errors);
        }

        $this->email = $data['email'];
        $this->showResetForm = true;

        Notification::make()
            ->title(__('Código de restablecimiento enviado'))
            ->body($result['message'])
            ->success()
            ->send();
    }

    public function confirmReset(): void
    {
        $data = $this->form->getState();

        $authService = app(ExternalAuthService::class);
        $result = $authService->resetPassword(
            $this->email ?? $data['email'],
            (string) ($data['code'] ?? ''),
            (string) ($data['password'] ?? ''),
        );

        if (! $result['success']) {
            throw ValidationException::withMessages($this->normalizeGatewayErrors($result['errors'], $result['message']));
        }

        Notification::make()
            ->title(__('Contraseña actualizada'))
            ->body($result['message'])
            ->success()
            ->send();

        $this->showResetForm = false;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('email')
                    ->label(__('Correo electrónico'))
                    ->email()
                    ->required()
                    ->autocomplete('email')
                    ->disabled(fn () => $this->showResetForm)
                    ->default($this->email),

                TextInput::make('code')
                    ->label(__('Código de verificación'))
                    ->required()
                    ->length(6)
                    ->numeric()
                    ->autofocus()
                    ->visible(fn () => $this->showResetForm),

                TextInput::make('password')
                    ->label(__('Nueva contraseña'))
                    ->password()
                    ->required()
                    ->autocomplete('new-password')
                    ->visible(fn () => $this->showResetForm),

                TextInput::make('passwordConfirmation')
                    ->label(__('Confirmar contraseña'))
                    ->password()
                    ->required()
                    ->same('password')
                    ->autocomplete('new-password')
                    ->visible(fn () => $this->showResetForm),
            ]);
    }

    public function getFormActions(): array
    {
        return [
            Action::make('request')
                ->label(__('Enviar código'))
                ->submit('request')
                ->visible(fn () => ! $this->showResetForm),

            Action::make('confirmReset')
                ->label(__('Guardar nueva contraseña'))
                ->submit('confirmReset')
                ->visible(fn () => $this->showResetForm),
        ];
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
            'email' => [$fallback],
        ];
    }
}
