<?php

namespace App\Filament\Pages\Auth;

use App\Services\ExternalAuthService;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
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
        if ($this->showResetForm) {
            $this->confirmReset();

            return;
        }

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

        Notification::make()
            ->title(__('Código de restablecimiento enviado'))
            ->body($result['message'])
            ->success()
            ->send();

        $this->email = $data['email'];
        $this->showResetForm = true;
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

        Log::debug('Response from password reset', [
            'errors' => $result['errors'] ?? null,
            'message' => $result['message'] ?? null,
            'success' => $result['success'] ?? null,
        ]);

        if (! $result['success']) {
            $errors = $this->normalizeGatewayErrors($result['errors'], $result['message']);
            Notification::make()
                ->title('Error')
                ->body($errors['password'][0] ?? '')
                ->danger()
                ->send();
            throw ValidationException::withMessages($errors);
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
                    ->dehydrated()
                    ->default($this->email),

                TextInput::make('code')
                    ->label(__('Código de verificación'))
                    ->required()
                    ->length(6)
                    ->numeric()
                    ->autofocus()
                    ->visible(fn () => $this->showResetForm),

                // !! VERIFICAR LA FORMA DE HACER QUE ESTE ELEMENTO ACTUALICE SU ESTADO CUANDO SE CAMBIE EL VALOR DE PASSWORD
                Callout::make(new HtmlString(sprintf(
                    '<p style="font-weight:700;">%s</p>',
                    __('Requisitos de la contraseña')
                )))
                    ->description(new HtmlString(sprintf(
                        '<div style="display:block; color:inherit; font-size:0.875rem; line-height:1.6;">
                            <ol style="margin:0; padding-left:1.25rem; list-style:decimal;">
                                <li style="margin-bottom:0.35rem;">%s</li>
                                <li style="margin-bottom:0.35rem;">%s</li>
                                <li style="margin-bottom:0.35rem;">%s</li>
                                <li>%s</li>
                            </ol>
                        </div>',
                        __('Tener al menos 8 caracteres de longitud.'),
                        __('Tener al menos una letra mayúscula, una letra minúscula y un símbolo.'),
                        __('Tener al menos un número.'),
                        __('No estar comprometida en una filtración de datos conocida.'),
                    )))
                    ->icon('heroicon-o-information-circle')
                    ->warning()
                    ->visible(fn () => $this->showResetForm),

                TextInput::make('password')
                    ->label(__('Nueva contraseña'))
                    ->password()
                    ->required()
                    ->autocomplete('new-password')
                    ->visible(fn () => $this->showResetForm)
                    ->revealable(true),

                TextInput::make('passwordConfirmation')
                    ->label(__('Confirmar contraseña'))
                    ->password()
                    ->required()
                    ->same('password')
                    ->autocomplete('new-password')
                    ->visible(fn () => $this->showResetForm)
                    ->revealable(true),
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
                ->label(__('Cambiar contraseña'))
                ->submit('request')
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
