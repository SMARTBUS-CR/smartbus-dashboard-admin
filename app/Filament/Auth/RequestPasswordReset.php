<?php

namespace App\Filament\Auth;

use App\Services\AuthService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    #[Locked]
    public bool $showResetForm = false;

    #[Locked]
    public ?string $requestedEmail = null;

    public function request(): void
    {
        try {
            $this->rateLimit(
                maxAttempts: 2,
                method: $this->showResetForm
                ? 'confirmPasswordReset'
                : 'requestPasswordResetCode',
            );
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        if ($this->showResetForm) {
            $this->confirmReset($data);

            return;
        }

        $result = app(AuthService::class)->sendPasswordResetCode(
            $data['email'],
        );

        if (! $result['success']) {
            $this->reportFailure($result);

            return;
        }

        $this->requestedEmail = $data['email'];
        $this->showResetForm = true;

        Notification::make()
            ->title(__('Reset Code Sent'))
            ->body(__($result['message']))
            ->success()
            ->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    ->autocomplete('email')
                    ->disabled(fn (): bool => $this->showResetForm)
                    ->dehydrated(),

                TextInput::make('code')
                    ->label(__('Reset Code'))
                    ->required()
                    ->rules(['regex:/^[0-9]{6}$/'])
                    ->autocomplete('one-time-code')
                    ->visible(fn (): bool => $this->showResetForm),

                TextInput::make('password')
                    ->label(__('New Password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(
                        Password::min(8)
                            ->letters()
                            ->mixedCase()
                            ->numbers()
                            ->symbols(),
                    )
                    ->autocomplete('new-password')
                    ->visible(fn (): bool => $this->showResetForm),

                TextInput::make('password_confirmation')
                    ->label(__('Confirm New Password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('password')
                    ->autocomplete('new-password')
                    ->visible(fn (): bool => $this->showResetForm),
            ]);
    }

    protected function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()
            ->label(
                fn (): string => $this->showResetForm
                    ? __('Reset Password')
                    : __('Send Reset Code'),
            );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function confirmReset(array $data): void
    {
        if ($this->requestedEmail === null) {
            return;
        }

        $result = app(AuthService::class)->resetPassword(
            $this->requestedEmail,
            $data['code'],
            $data['password'],
        );

        if (! $result['success']) {
            $this->reportFailure($result);

            return;
        }

        session()->flash('password_reset_email', $this->requestedEmail);

        $this->showResetForm = false;
        $this->requestedEmail = null;

        $this->form->fill([
            'email' => null,
            'code' => null,
            'password' => null,
            'password_confirmation' => null,
        ]);

        $this->resetValidation();

        Notification::make()
            ->title(__('Password Updated'))
            ->body(__($result['message']))
            ->success()
            ->send();

        $this->redirect(Filament::getLoginUrl());
    }

    /**
     * @param  array{
     *     success: bool,
     *     message: string,
     *     errors: array<string, array<int, string>>
     * }  $result
     */
    private function reportFailure(array $result): void
    {
        if ($result['errors'] !== []) {
            $errors = [];

            foreach ($result['errors'] as $field => $messages) {
                $errors["data.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        Notification::make()
            ->title(__('Password Recovery Failed'))
            ->body(__($result['message']))
            ->danger()
            ->send();
    }
}
