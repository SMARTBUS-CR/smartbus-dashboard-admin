<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['services.smartbus.gateway.url' => 'https://gateway.test']);
    Route::get('/api/testing/external-token', fn (Request $request) => response()->json(['user_id' => $request->user()->id]))
        ->middleware('auth.api');
    Http::preventStrayRequests();
});

test('returns 503 when token validation is unavailable instead of invalidating the credentials', function (int $status): void {
    Http::fake(['https://gateway.test/auth/token/validate' => Http::response([], $status)]);

    $this->withToken('user-token')->getJson('/api/testing/external-token')->assertServiceUnavailable()
        ->assertJsonPath('errors.0.status', '503')
        ->assertJsonPath('errors.0.title', 'Service Unavailable');
    Http::assertSentCount(1);
})->with([500, 503, 200]);

test('returns 503 when the authentication service cannot be reached', function (): void {
    Http::fake(['https://gateway.test/auth/token/validate' => Http::failedConnection()]);

    $this->withToken('user-token')->getJson('/api/testing/external-token')->assertServiceUnavailable();
});

test('returns 503 when token validation succeeds but user lookup is unavailable', function (): void {
    Http::fake([
        'https://gateway.test/auth/token/validate' => Http::response(['meta' => ['valid' => true]]),
        'https://gateway.test/auth/user*' => Http::response([], 503),
    ]);

    $this->withToken('user-token')->getJson('/api/testing/external-token')->assertServiceUnavailable()
        ->assertJsonPath('errors.0.status', '503');
    Http::assertSentCount(2);
});

test('preserves invalid and forbidden authentication responses', function (int $status, int $expected): void {
    Http::fake(['https://gateway.test/auth/token/validate' => Http::response(['meta' => ['valid' => false]], $status)]);

    $this->withToken('user-token')->getJson('/api/testing/external-token')->assertStatus($expected)
        ->assertJsonPath('errors.0.status', (string) $expected);
})->with([[401, 401], [403, 403], [200, 401]]);

test('resolves an authenticated actor after successful token validation', function (): void {
    $user = User::factory()->create();
    Http::fake([
        'https://gateway.test/auth/token/validate' => Http::response(['meta' => ['valid' => true]]),
        'https://gateway.test/auth/user*' => Http::response(['data' => ['id' => $user->id]]),
    ]);

    $this->withToken('user-token')->getJson('/api/testing/external-token')->assertOk()->assertJsonPath('user_id', $user->id);
    Http::assertSentCount(2);
});
