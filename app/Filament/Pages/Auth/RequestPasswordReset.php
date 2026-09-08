<?php

namespace App\Filament\Pages\Auth;

use App\Services\ExternalAuthService;
use App\Traits\ApiLogger;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    use ApiLogger;

    public bool $showResetForm = false;

    public ?string $email = null;

    public ?string $code = null;

    public ?string $password = null;

    public ?string $passwordConfirmation = null;

    /**
     * Handle the password reset request process.
     */
    public function request(): void
    {
        if ($this->showResetForm) {
            $this->confirmReset();

            return;
        }

        $data = $this->form->getState();

        $authService = app(ExternalAuthService::class);
        $result = $authService->sendPasswordResetCode($data['email']);

        if (! $result['success']) {
            $this->log('error', 'Password reset request failed', [
                'email' => $data['email'],
                'errors' => $result['errors'] ?? null,
                'message' => $result['message'] ?? null,
            ]);

            $errors = $this->normalizeGatewayErrors($result['errors'], $result['message']);
            Notification::make()
                ->title($errors['email'][0] ?? '')
                ->body(__('passwords.user'))
                ->danger()
                ->send();
            throw ValidationException::withMessages($errors);
        }

        Notification::make()
            ->title(__('Reset password code sent'))
            ->body($result['message'])
            ->success()
            ->send();

        $this->email = $data['email'];
        $this->showResetForm = true;
    }

    /**
     * Handle the password reset confirmation process.
     */
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
            $this->log('error', 'Password reset failed', [
                'email' => $this->email ?? $data['email'],
                'errors' => $result['errors'] ?? null,
                'message' => $result['message'] ?? null,
            ]);

            $errors = $this->normalizeGatewayErrors($result['errors'], $result['message']);
            Notification::make()
                ->title('Error')
                ->body($errors['password'][0] ?? '')
                ->danger()
                ->send();
            throw ValidationException::withMessages($errors);
        }

        Notification::make()
            ->title(__('Password Updated'))
            ->body($result['message'])
            ->success()
            ->send();

        $this->showResetForm = false;
        $this->form->fill();
    }

    /**
     * Define the form schema for the password reset request and confirmation.
     *
     * @param  Schema  $schema  Schema instance to define the form structure.
     * @return Schema Modified schema with form components for password reset.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('email')
                    ->label(__('form.email.label'))
                    ->email()
                    ->required()
                    ->autocomplete('email')
                    ->disabled(fn () => $this->showResetForm)
                    ->dehydrated()
                    ->default($this->email),

                TextInput::make('code')
                    ->label(__('form.request-password.code.label'))
                    ->required()
                    ->length(6)
                    ->numeric()
                    ->autofocus()
                    ->visible(fn () => $this->showResetForm),

                TextInput::make('password')
                    ->label(__('form.password.label'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(
                        Password::min(8)
                            ->letters()
                            ->mixedCase()
                            ->numbers()
                            ->symbols()
                            ->uncompromised(),
                    )
                    ->autocomplete('new-password')
                    ->visible(fn () => $this->showResetForm),

                TextInput::make('passwordConfirmation')
                    ->label(__('form.password-confirmation.label'))
                    ->password()
                    ->required()
                    ->same('password')
                    ->autocomplete('new-password')
                    ->visible(fn () => $this->showResetForm)
                    ->revealable(true),
            ]);
    }

    /**
     * Define the actions available on the password reset form.
     *
     * @return array List of actions for the form, including request and confirm reset actions.
     */
    public function getFormActions(): array
    {
        return [
            Action::make('request')
                ->label(__('form.request-password.actions.request.label'))
                ->submit('request')
                ->visible(fn () => ! $this->showResetForm),

            Action::make('confirmReset')
                ->label(__('form.request-password.actions.confirm-reset.label'))
                ->submit('request')
                ->visible(fn () => $this->showResetForm),
        ];
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
            'email' => [$fallback],
        ];
    }
}
