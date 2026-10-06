<?php

use App\Enums\UserRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\SuperAdmins\SuperAdminResource;
use App\Models\CompanyUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

describe('Global SuperAdmin Resource HTTP Access', function (): void {
    beforeEach(function (): void {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response([
                'meta' => ['valid' => true],
            ]),
        ]);

        Filament::setCurrentPanel('admin');
        Filament::setTenant(null, isQuiet: true);
        setPermissionsTeamId(null);
    });

    test('allows a super-admin to access global accounts from a selected company', function (string $page): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, createCompany());

        $parameters = $page === 'edit'
            ? ['record' => $target->getRouteKey()]
            : [];

        $this->withSession([
            'external_auth_token' => 'test-token',
        ])
            ->get(SuperAdminResource::getUrl(
                $page,
                $parameters,
                panel: 'admin',
            ))
            ->assertOk();

        expect(
            CompanyUser::withTrashed()
                ->whereIn('user_id', [
                    $actor->getKey(),
                    $target->getKey(),
                ])
                ->exists()
        )->toBeFalse();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.test/auth/token/validate'
            && $request->hasHeader('Authorization', 'Bearer test-token')
        );

        $this->assertAuthenticatedAs($actor, 'web');
    })->with(['index', 'create', 'edit']);

    test('forbids a company admin from accessing global pages directly', function (string $page): void {
        $target = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        grantShield($actor, [
            'ViewAny:User',
            'Create:User',
            'View:User',
            'Update:User',
        ], $company);

        $parameters = $page === 'edit'
            ? ['record' => $target->getRouteKey()]
            : [];

        $this->withSession([
            'external_auth_token' => 'test-token',
        ])
            ->get(SuperAdminResource::getUrl(
                $page,
                $parameters,
                panel: 'admin',
            ))
            ->assertForbidden()
            ->assertSessionHas('external_auth_token', 'test-token');

        $this->assertAuthenticatedAs($actor, 'web');
    })->with(['index', 'create', 'edit']);

    test('allows switching companies without creating super-admin membership', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);

        [$company, $foreign] = createTenantPair();

        actingAsInCompany($actor, $company);

        $foreignUrl = Dashboard::getUrl(
            panel: 'admin',
            tenant: $foreign,
        );

        $this->withSession([
            'external_auth_token' => 'test-token',
        ])
            ->get(SuperAdminResource::getUrl(
                'index',
                panel: 'admin',
            ))
            ->assertOk()
            ->assertSee($foreign->legal_name)
            ->assertSee('href="'.e($foreignUrl).'"', escape: false);

        $this->get($foreignUrl)
            ->assertOk()
            ->assertSee($foreign->legal_name);

        expect(Filament::getTenant()?->getKey())->toBe($foreign->getKey())
            ->and(getPermissionsTeamId())->toBe($foreign->getKey())
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $actor->getKey())->exists())->toBeFalse();

        $this->assertAuthenticatedAs($actor, 'web');
    });
});
