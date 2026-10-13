<?php

use App\Enums\UserRole;
use App\Filament\Auth\Login;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Login Failure Feedback', function (): void {
    test('reports service failures without credential errors or losing the notice', function (?int $status, string $message, array $payload = []): void {
        Filament::setCurrentPanel('admin');
        config(['services.smartbus.gateway.url' => 'https://gateway.test']);
        $user = createUserWithRole(UserRole::SuperAdmin);
        Http::preventStrayRequests();
        Http::fake(['https://gateway.test/auth/login*' => $status === null ? Http::failedConnection() : Http::response($payload, $status)]);
        $this->withSession(['auth_notice' => 'session_ended']);

        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'ValidPassword123!'])
            ->call('authenticate')->assertHasNoFormErrors();

        Notification::assertNotified(Notification::make()->title(__('Sign In Failed'))->body(__($message))->danger()->persistent());
        expect(session('auth_notice'))->toBe('session_ended');
        $this->assertGuest('web');
        Http::assertSentCount(1);
    })->with([
        'unavailable' => [503, 'The authentication service is unavailable. Please try again shortly.'],
        'forbidden' => [403, 'Access was denied by the authentication service.'],
        'connection failure' => [null, 'The authentication service is unavailable. Please try again shortly.'],
        'malformed success' => [200, 'The authentication service is unavailable. Please try again shortly.'],
        'email verification required' => [403, 'Verify your email address before signing in.', ['errors' => [['code' => 'email_not_verified']]]],
    ]);

});
