<?php

use App\Services\AuthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('Authentication Password Reset', function (): void {
    test('preserves the gateway message when the password reset code is incorrect', function (): void {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/reset' => Http::response([
                'errors' => [
                    [
                        'status' => '400',
                        'title' => 'Incorrect Code',
                        'detail' => 'The reset code is incorrect.',
                    ],
                ],
            ], 400),
        ]);

        $result = app(AuthService::class)->resetPassword(
            'admin@example.com',
            '123456',
            'NewSecurePassword123!',
        );

        expect($result['success'])->toBeFalse()
            ->and($result)->toMatchArray(['message' => 'The reset code is incorrect.', 'errors' => []]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/auth/password/reset'
                && $request->data() === [
                    'email' => 'admin@example.com',
                    'code' => '123456',
                    'password' => 'NewSecurePassword123!',
                    'password_confirmation' => 'NewSecurePassword123!',
                ],
        );

        Http::assertSentCount(1);
    });

    test('preserves field validation errors when requesting a password reset code', function (): void {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'message' => 'The email field must be a valid email address.',
                'errors' => [
                    'email' => [
                        'The email field must be a valid email address.',
                    ],
                ],
            ], 422),
        ]);

        $result = app(AuthService::class)->sendPasswordResetCode(
            'invalid-email',
        );

        expect($result)->toBe([
            'success' => false,
            'message' => 'The email field must be a valid email address.',
            'errors' => [
                'email' => [
                    'The email field must be a valid email address.',
                ],
            ],
        ]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/auth/password/forgot'
                && $request->data() === [
                    'email' => 'invalid-email',
                ],
        );

        Http::assertSentCount(1);
    });

    test('requests a password reset code through the gateway', function (): void {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'A password reset code has been sent to your email.',
                ],
            ]),
        ]);

        $result = app(AuthService::class)->sendPasswordResetCode(
            'admin@example.com',
        );

        expect($result)->toBe([
            'success' => true,
            'message' => 'A password reset code has been sent to your email.',
            'errors' => [],
        ]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/auth/password/forgot'
                && $request->data() === [
                    'email' => 'admin@example.com',
                ],
        );

        Http::assertSentCount(1);
    });

    test('resets the password through the gateway using the provided code', function (): void {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/reset' => Http::response([
                'meta' => [
                    'message' => 'Your password has been reset.',
                ],
            ]),
        ]);

        $result = app(AuthService::class)->resetPassword(
            'admin@example.com',
            '123456',
            'NewSecurePassword123!',
        );

        expect($result)->toBe([
            'success' => true,
            'message' => 'Your password has been reset.',
            'errors' => [],
        ]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/auth/password/reset'
                && $request->data() === [
                    'email' => 'admin@example.com',
                    'code' => '123456',
                    'password' => 'NewSecurePassword123!',
                    'password_confirmation' => 'NewSecurePassword123!',
                ],
        );

        Http::assertSentCount(1);
    });

    test('reports failure when the gateway cannot be reached', function (string $operation): void {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        $endpoint = match ($operation) {
            'request' => '/auth/password/forgot',
            'reset' => '/auth/password/reset',
        };

        Http::fake([
            'https://gateway.test'.$endpoint => Http::failedConnection(),
        ]);

        $service = app(AuthService::class);

        $result = match ($operation) {
            'request' => $service->sendPasswordResetCode('admin@example.com'),
            'reset' => $service->resetPassword(
                'admin@example.com',
                '123456',
                'NewSecurePassword123!',
            ),
        };

        expect($result)->toBe([
            'success' => false,
            'message' => 'Password reset request failed due to an internal error.',
            'errors' => [],
        ]);
    })->with([
        'requesting a reset code' => ['request'],
        'resetting the password' => ['reset'],
    ]);
});
