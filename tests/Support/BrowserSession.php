<?php

namespace Tests\Support;

use App\Models\User;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class BrowserSession
{
    public const string VALIDATE_URL = 'https://gateway.test/auth/token/validate';

    /** @param array<string, mixed> $responses */
    public static function start(User $actor, array $responses = []): void
    {
        config(['services.smartbus.gateway.url' => 'https://gateway.test']);
        test()->actingAs($actor);
        test()->withSession(['external_auth_token' => 'browser-test-token']);

        // Pest reuses the application between requests; navigation is request-scoped.
        app()->forgetInstance(NavigationManager::class);
        Event::listen(RequestHandled::class, function (): void {
            app()->forgetInstance(NavigationManager::class);
        });

        Http::preventStrayRequests();
        Http::fake([
            self::VALIDATE_URL => Http::response(['meta' => ['valid' => true]], 200),
            ...$responses,
        ]);
    }

    public static function assertTokenWasValidated(): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === self::VALIDATE_URL
            && $request->hasHeader('Authorization', 'Bearer browser-test-token'));
    }
}
