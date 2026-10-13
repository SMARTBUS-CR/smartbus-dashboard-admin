<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\ListRoutes;
use App\Models\CompanyUser;
use App\Models\Route;
use Livewire\Livewire;

describe('Route Resource Action Security', function (): void {
    test('rejects a direct action attempt without its permission', function (string $action, bool $archived): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        if ($archived) {
            $route->delete();
        }

        $actor = createUserWithRole('route-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route'],
            $company,
        );

        actingAsInCompany($actor, $company);

        $component = Livewire::test(ListRoutes::class);

        if ($archived) {
            $component->filterTable('trashed', false);
        }

        $component
            ->assertCanSeeTableRecords([$route])
            ->call('mountAction', $action, [], [
                'table' => true,
                'recordKey' => $route->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        if ($archived) {
            $this->assertSoftDeleted($route);
        } else {
            $this->assertNotSoftDeleted($route);
        }
    })->with([
        'unauthorized archival' => ['delete', false],
        'unauthorized restoration' => ['restore', true],
    ]);

    test('rejects a direct action against another company route even for a system admin', function (string $action, bool $archived): void {
        [$company, $otherCompany] = createTenantPair();

        $foreignRoute = Route::factory()->for($otherCompany)->create();

        if ($archived) {
            $foreignRoute->delete();
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $component = Livewire::test(ListRoutes::class);

        if ($archived) {
            $component->filterTable('trashed', false);
        }

        $component
            ->assertCanNotSeeTableRecords([$foreignRoute])
            ->call('mountAction', $action, [], [
                'table' => true,
                'recordKey' => $foreignRoute->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        if ($archived) {
            $this->assertSoftDeleted($foreignRoute);
        } else {
            $this->assertNotSoftDeleted($foreignRoute);
        }

        $this->assertDatabaseHas(Route::class, [
            'id' => $foreignRoute->getKey(),
            'company_id' => $otherCompany->getKey(),
        ]);
    })->with([
        'foreign archival' => ['delete', false],
        'foreign restoration' => ['restore', true],
    ]);
});
