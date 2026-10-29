<?php

use App\Enums\UserRole;
use App\Filament\Auth\Login;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Login Session Notices', function (): void {
    test('shows the appropriate message for a session notice', function (string $reason, string $message): void {
        Filament::setCurrentPanel('admin');

        $this->withSession(['auth_notice' => $reason]);

        Livewire::test(Login::class);

        Notification::assertNotified(
            Notification::make()
                ->title(__('Session Ended'))
                ->body(__($message))
                ->warning()
                ->persistent(),
        );
    })->with([
        'invalid session' => [
            'session_ended',
            'Your session is no longer valid. Please sign in again.',
        ],
        'missing token' => [
            'token_missing',
            'Your session could not be verified. Please sign in again.',
        ],
        'deactivated account' => [
            'account_deactivated',
            'Your account has been deactivated. Contact a system admin for assistance.',
        ],
        'unknown reason' => [
            'unexpected_reason',
            'Your session ended unexpectedly. Please sign in again.',
        ],
    ]);

    test('does not show a session notice on a normal login visit', function (): void {
        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class);

        Notification::assertNotNotified();
    });

    test('clears the session notice after a successful login', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        $user = createUserWithRole(UserRole::SuperAdmin);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/login*' => Http::response([
                'data' => [
                    'type' => 'users',
                    'id' => $user->id,
                    'attributes' => [
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
                'meta' => [
                    'access_token' => 'new-test-token',
                    'token_type' => 'Bearer',
                ],
            ]),
        ]);

        $this->withSession(['auth_notice' => 'session_ended']);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'ValidPassword123!',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user, 'web');

        expect(session()->has('auth_notice'))->toBeFalse()
            ->and(session('external_auth_token'))->toBe('new-test-token');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.test/auth/login?include=roles'
            && $request['email'] === $user->email
        );
    });

    test('preserves the session notice when login credentials are rejected', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        $user = createUserWithRole(UserRole::SuperAdmin);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/login*' => Http::response([], 401),
        ]);

        $this->withSession(['auth_notice' => 'session_ended']);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'IncorrectPassword123!',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest('web');

        expect(session('auth_notice'))->toBe('session_ended');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.test/auth/login?include=roles'
            && $request['email'] === $user->email
        );
    });
});
