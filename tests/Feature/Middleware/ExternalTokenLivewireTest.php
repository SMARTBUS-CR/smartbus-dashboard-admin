<?php

use App\Enums\UserRole;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\EditCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

describe('External Token Validation In Livewire', function (): void {
    it('validates the token again before saving an opened page', function (int $validationStatus): void {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        $company = createCompany();
        $originalName = $company->legal_name;
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($superAdmin, $company);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::sequence()
                ->push(['meta' => ['valid' => true]], 200)
                ->push(
                    $validationStatus === 200
                    ? ['meta' => ['valid' => true]]
                    : [],
                    $validationStatus,
                ),
            'https://gateway.test/auth/logout' => Http::response([
                'meta' => ['message' => 'Logged out'],
            ]),
        ]);

        $page = $this->withSession([
            'external_auth_token' => 'test-token',
            'session_marker' => 'preserved',
        ])->get(CompanyResource::getUrl('edit', [
            'record' => $company->getRouteKey(),
        ], tenant: $company));

        $page->assertOk();

        preg_match_all(
            '/wire:snapshot="([^"]+)"/',
            $page->getContent(),
            $matches,
        );

        $snapshot = null;

        $expectedComponentName = app('livewire')
            ->new(EditCompany::class)
            ->getName();

        foreach ($matches[1] as $encodedSnapshot) {
            $candidate = html_entity_decode(
                $encodedSnapshot,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );

            $decoded = json_decode(
                $candidate,
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if (($decoded['memo']['name'] ?? null) === $expectedComponentName) {
                $snapshot = $candidate;

                break;
            }
        }

        expect($snapshot)->not->toBeNull();

        $response = $this->postJson(
            app('livewire')->getUpdateUri(),
            [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [
                        'data.legal_name' => 'Updated Company',
                    ],
                    'calls' => [[
                        'path' => '',
                        'method' => 'save',
                        'params' => [],
                    ]],
                ]],
            ],
            ['X-Livewire' => 'true'],
        );

        if ($validationStatus === 200) {
            $response->assertOk();

            expect($company->fresh()->legal_name)
                ->toBe('Updated Company');

            $this->assertAuthenticated('web');
        } else {
            expect($company->fresh()->legal_name)
                ->toBe($originalName);

            if ($validationStatus === 401) {
                $response
                    ->assertRedirect(Filament::getLoginUrl())
                    ->assertSessionMissing('external_auth_token');

                $this->assertGuest('web');
            } else {
                $response
                    ->assertStatus($validationStatus)
                    ->assertSessionHas('external_auth_token', 'test-token')
                    ->assertSessionHas('session_marker', 'preserved');

                $this->assertAuthenticated('web');
            }
        }

        Http::assertSentCount(
            $validationStatus === 401 ? 3 : 2,
        );
    })->with([
        'valid token' => [200],
        'expired token' => [401],
        'forbidden access' => [403],
        'unavailable service' => [503],
    ]);
});
