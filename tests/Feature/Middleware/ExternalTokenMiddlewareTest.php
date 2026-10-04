<?php

use App\Http\Middleware\EnsureExternalTokenIsValid;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config([
        'services.smartbus.gateway.url' => 'https://gateway.test',
    ]);

    Filament::setCurrentPanel('admin');

    Route::middleware(['web', EnsureExternalTokenIsValid::class])
        ->post('/_tests/protected-action', function () {
            session(['protected_action_executed' => true]);

            return response()->noContent();
        });

    Http::preventStrayRequests();
});

describe('External Token Validation', function (): void {
    it('allows the action when the token is valid', function (): void {
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response([
                'meta' => ['valid' => true],
            ]),
        ]);

        $this->actingAs(User::factory()->create(), 'web');

        $this->withSession(['external_auth_token' => 'test-token'])
            ->post('/_tests/protected-action')
            ->assertNoContent()
            ->assertSessionHas('protected_action_executed', true);

        $this->assertAuthenticated('web');

        Http::assertSentCount(1);
    });

    it('logs out when the token is invalid', function (): void {
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response([], 401),
            'https://gateway.test/auth/logout' => Http::response([
                'meta' => ['message' => 'Logged out'],
            ]),
        ]);

        $this->actingAs(User::factory()->create(), 'web');

        $this->withSession(['external_auth_token' => 'test-token'])
            ->post('/_tests/protected-action')
            ->assertRedirect(Filament::getLoginUrl())
            ->assertSessionMissing('external_auth_token')
            ->assertSessionMissing('protected_action_executed');

        $this->assertGuest('web');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.test/auth/logout'
            && $request->hasHeader('Authorization', 'Bearer test-token')
        );
    });

    it('logs out without contacting the gateway when the token is missing', function (): void {
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response([
                'meta' => ['valid' => true],
            ]),
        ]);

        $this->actingAs(User::factory()->create(), 'web');

        $this->post('/_tests/protected-action')
            ->assertRedirect(Filament::getLoginUrl())
            ->assertSessionMissing('protected_action_executed');

        $this->assertGuest('web');

        Http::assertNothingSent();
    });

    it('blocks the action and preserves the session', function (int $upstreamStatus, int $expectedStatus): void {
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response(
                [],
                $upstreamStatus
            ),
        ]);

        $this->actingAs(User::factory()->create(), 'web');

        $this->withSession([
            'external_auth_token' => 'test-token',
            'session_marker' => 'preserved',
        ])
            ->post('/_tests/protected-action')
            ->assertStatus($expectedStatus)
            ->assertSessionHas('external_auth_token', 'test-token')
            ->assertSessionHas('session_marker', 'preserved')
            ->assertSessionMissing('protected_action_executed');

        $this->assertAuthenticated('web');

        Http::assertSentCount(1);
    })->with([
        'forbidden' => [403, 403],
        'unavailable' => [503, 503],
    ]);

    it('preserves the session when token validation cannot connect', function (): void {
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::failedConnection(),
        ]);

        $this->actingAs(User::factory()->create(), 'web');

        $this->withSession(['external_auth_token' => 'test-token'])
            ->post('/_tests/protected-action')
            ->assertServiceUnavailable()
            ->assertSessionHas('external_auth_token', 'test-token')
            ->assertSessionMissing('protected_action_executed');

        $this->assertAuthenticated('web');
    });
});
