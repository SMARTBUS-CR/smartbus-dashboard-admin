<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use Illuminate\Support\Facades\Http;

describe('Nested Route Pattern Resource Access', function (): void {
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
    });

    test('opens the appropriate pattern page for an authorized user', function (string $role, string $page): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create([
            'code' => 'OUTBOUND',
            'name' => 'Via Central Market',
            'headsign' => 'Heredia Terminal',
        ]);

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            $permissions = [
                'ViewAny:Route',
                'View:Route',
                'ViewAny:RoutePattern',
                'View:RoutePattern',
            ];

            if ($page === 'edit') {
                $permissions[] = 'Update:RoutePattern';
            }

            grantShield($actor, $permissions, $company);
        }

        actingAsInCompany($actor, $company);

        $this->withSession(['external_auth_token' => 'test-token'])
            ->get(RoutePatternResource::getUrl(
                $page,
                [
                    'route' => $route->getKey(),
                    'record' => $pattern->getKey(),
                ],
                panel: 'admin',
                tenant: $company,
            ))
            ->assertOk()
            ->assertSee('OUTBOUND')
            ->assertSee('Heredia Terminal');
    })->with([
        'system admin editing' => [UserRole::SuperAdmin->value, 'edit'],
        'company admin editing' => [UserRole::Admin->value, 'edit'],
        'custom role editing' => ['route-editor', 'edit'],
        'custom role viewing' => ['route-viewer', 'view'],
    ]);

    test('denies editing to a user with pattern viewing permissions only', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $actor = createUserWithRole('route-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
            'ViewAny:RoutePattern',
            'View:RoutePattern',
        ], $company);

        actingAsInCompany($actor, $company);

        $this->withSession(['external_auth_token' => 'test-token'])
            ->get(RoutePatternResource::getUrl(
                'edit',
                [
                    'route' => $route->getKey(),
                    'record' => $pattern->getKey(),
                ],
                panel: 'admin',
                tenant: $company,
            ))
            ->assertForbidden();
    });

    test('rejects a pattern that does not belong to the route in the URL', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();
        $foreignPattern = RoutePattern::factory()->for($otherRoute)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $this->withSession(['external_auth_token' => 'test-token'])
            ->get(RoutePatternResource::getUrl(
                'view',
                [
                    'route' => $route->getKey(),
                    'record' => $foreignPattern->getKey(),
                ],
                panel: 'admin',
                tenant: $company,
            ))
            ->assertNotFound();
    });

    test('rejects a parent route from another selected company', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $foreignRoute = Route::factory()->for($otherCompany)->create();
        $foreignPattern = RoutePattern::factory()
            ->for($foreignRoute)
            ->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $this->withSession(['external_auth_token' => 'test-token'])
            ->get(RoutePatternResource::getUrl(
                'view',
                [
                    'route' => $foreignRoute->getKey(),
                    'record' => $foreignPattern->getKey(),
                ],
                panel: 'admin',
                tenant: $company,
            ))
            ->assertNotFound();
    });
});
