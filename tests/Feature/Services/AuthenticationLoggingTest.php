<?php

use App\Enums\UserRole;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

describe('Authentication Logging', function (): void {
    test('does not log credentials or access tokens during successful authentication', function (): void {
        config(['services.smartbus.gateway.url' => 'https://gateway.test', 'services.smartbus.gateway.log_failures' => true]);
        $user = createUserWithRole(UserRole::SuperAdmin);
        Http::preventStrayRequests();
        Http::fake(['https://gateway.test/auth/login*' => Http::response([
            'data' => ['id' => $user->id, 'type' => 'users', 'attributes' => ['email' => $user->email]],
            'meta' => ['access_token' => 'private-login-token'],
        ])]);
        Log::spy();

        expect(Auth::guard('web')->getProvider()->validateCredentials($user, ['email' => $user->email, 'password' => 'private-password']))->toBeTrue();

        Log::shouldHaveReceived('debug')->once()->withArgs(fn (string $message, array $context): bool => ! str_contains($message.json_encode($context), 'private-login-token')
            && ! str_contains($message.json_encode($context), 'private-password')
        );
        Http::assertSentCount(1);
    });

    test('never logs an access token when logout fails', function (): void {
        config(['services.smartbus.gateway.url' => 'https://gateway.test', 'services.smartbus.gateway.log_failures' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://gateway.test/auth/logout' => Http::response([], 503)]);
        Log::spy();

        expect(app(AuthService::class)->logout('sensitive-access-token'))->toBeFalse();

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => ! str_contains($message.json_encode($context), 'sensitive-access-token')
        );
        Http::assertSentCount(1);
    });
});
