<?php

use App\Enums\TokenValidationStatus;
use App\Services\AuthService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class);

describe('Auth Service Token Validation', function (): void {
    beforeEach(function (): void {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();
    });

    test('classifies token validation responses', function (int $status, array $payload, string $expected): void {
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response(
                $payload,
                $status
            ),
        ]);

        $result = app(AuthService::class)->validateToken('test-token');

        expect($result)->toBe(TokenValidationStatus::from($expected));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/auth/token/validate'
            && $request->hasHeader('Authorization', 'Bearer test-token')
        );

        Http::assertSentCount(1);
    })->with([
        'valid token' => [200, ['meta' => ['valid' => true]], 'valid'],
        'explicit invalid token' => [200, ['meta' => ['valid' => false]], 'invalid'],
        'unauthorized' => [401, [], 'invalid'],
        'forbidden' => [403, [], 'forbidden'],
        'server failure' => [503, [], 'unavailable'],
        'rate limited' => [429, [], 'unavailable'],
        'unexpected status' => [404, [], 'unavailable'],
        'missing validity' => [200, ['meta' => []], 'unavailable'],
        'incorrect validity type' => [
            200,
            ['meta' => ['valid' => 'true']],
            'unavailable',
        ],
    ]);

    test('preserves an inconclusive result when connection fails', function (): void {
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::failedConnection(),
        ]);

        $result = app(AuthService::class)->validateToken('test-token');

        expect($result)->toBe(TokenValidationStatus::Unavailable);
    });

    test('rejects an empty token without contacting the gateway', function (): void {
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response(
                ['meta' => ['valid' => true]]
            ),
        ]);

        $result = app(AuthService::class)->validateToken('   ');

        expect($result)->toBe(TokenValidationStatus::Invalid);

        Http::assertNothingSent();
    });

});
