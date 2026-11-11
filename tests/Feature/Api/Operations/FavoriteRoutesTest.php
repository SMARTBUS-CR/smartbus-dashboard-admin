<?php

use App\Enums\CompanyStatus;
use App\Models\FavoriteRoute;
use App\Models\Route;
use App\Models\User;

describe('Passenger Favorite Routes Listing', function (): void {
    it('requires authentication to access favorites', function (): void {
        $response = $this->getJson('/api/v1/operations/favorites');

        $response->assertStatus(401)
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.status', '401');
    });

    it('lists favorite routes belonging exclusively to the authenticated passenger', function (): void {
        $passengerA = User::factory()->create();
        $passengerB = User::factory()->create();

        $company = createCompany();
        $route1 = Route::factory()->for($company)->create(['name' => 'Route 101']);
        $route2 = Route::factory()->for($company)->create(['name' => 'Route 202']);
        $route3 = Route::factory()->for($company)->create(['name' => 'Route 303']);

        $favA1 = FavoriteRoute::factory()->create([
            'user_id' => $passengerA->id,
            'route_id' => $route1->id,
            'label' => 'Home',
        ]);
        $favA2 = FavoriteRoute::factory()->create([
            'user_id' => $passengerA->id,
            'route_id' => $route2->id,
            'label' => 'Work',
        ]);
        FavoriteRoute::factory()->create([
            'user_id' => $passengerB->id,
            'route_id' => $route3->id,
            'label' => 'Other',
        ]);

        $this->actingAs($passengerA);

        $response = $this->getJson('/api/v1/operations/favorites');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toContain($favA1->id, $favA2->id);

        $routeIds = collect($response->json('data'))->pluck('attributes.route_id')->all();
        expect($routeIds)->toContain($route1->id, $route2->id)
            ->and($routeIds)->not->toContain($route3->id);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'type',
                    'id',
                    'attributes' => [
                        'user_id',
                        'route_id',
                        'label',
                        'created_at',
                        'updated_at',
                    ],
                    'relationships' => [
                        'route',
                    ],
                ],
            ],
        ]);
    });
});

describe('Passenger Favorite Routes Creation', function (): void {
    it('allows an authenticated passenger to bookmark a valid route with an optional label', function (): void {
        $passenger = User::factory()->create();
        $company = createCompany();
        $route = Route::factory()->for($company)->create(['name' => 'San Jose - Heredia']);

        $this->actingAs($passenger);

        $response = $this->postJson('/api/v1/operations/favorites', [
            'route_id' => $route->id,
            'label' => 'Daily Commute',
        ]);

        $response->assertCreated()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('data.type', 'favorite-routes')
            ->assertJsonPath('data.attributes.route_id', $route->id)
            ->assertJsonPath('data.attributes.user_id', $passenger->id)
            ->assertJsonPath('data.attributes.label', 'Daily Commute');

        $this->assertDatabaseHas('favorite_routes', [
            'user_id' => $passenger->id,
            'route_id' => $route->id,
            'label' => 'Daily Commute',
        ]);
    });

    it('rejects duplicate bookmarks with 409 Conflict', function (): void {
        $passenger = User::factory()->create();
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        FavoriteRoute::factory()->create([
            'user_id' => $passenger->id,
            'route_id' => $route->id,
            'label' => 'Existing',
        ]);

        $this->actingAs($passenger);

        $response = $this->postJson('/api/v1/operations/favorites', [
            'route_id' => $route->id,
            'label' => 'Duplicate attempt',
        ]);

        $response->assertStatus(409)
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.status', '409');
    });

    it('rejects bookmarking an invalid route with 422', function (): void {
        $passenger = User::factory()->create();
        $this->actingAs($passenger);

        $response = $this->postJson('/api/v1/operations/favorites', [
            'route_id' => '00000000-0000-0000-0000-000000000000',
            'label' => 'Invalid',
        ]);

        $response->assertUnprocessable();
    });

    it('rejects bookmarking a route from an inactive company with 422', function (): void {
        $passenger = User::factory()->create();
        $inactiveCompany = createCompany(['status' => CompanyStatus::INACTIVE]);
        $route = Route::factory()->for($inactiveCompany)->create();

        $this->actingAs($passenger);

        $response = $this->postJson('/api/v1/operations/favorites', [
            'route_id' => $route->id,
            'label' => 'Inactive Company Route',
        ]);

        $response->assertUnprocessable();
    });
});

describe('Passenger Favorite Routes Modification', function (): void {
    it('allows an authenticated passenger to update their favorite label', function (): void {
        $passenger = User::factory()->create();
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        FavoriteRoute::factory()->create([
            'user_id' => $passenger->id,
            'route_id' => $route->id,
            'label' => 'Old Label',
        ]);

        $this->actingAs($passenger);

        $response = $this->patchJson("/api/v1/operations/favorites/{$route->id}", [
            'label' => 'New Label',
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('data.attributes.label', 'New Label');

        $this->assertDatabaseHas('favorite_routes', [
            'user_id' => $passenger->id,
            'route_id' => $route->id,
            'label' => 'New Label',
        ]);
    });

    it('returns 404 when attempting to update a route not in favorites', function (): void {
        $passenger = User::factory()->create();
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $this->actingAs($passenger);

        $response = $this->patchJson("/api/v1/operations/favorites/{$route->id}", [
            'label' => 'Non-existent',
        ]);

        $response->assertNotFound();
    });
});

describe('Passenger Favorite Routes Deletion', function (): void {
    it('allows an authenticated passenger to delete a favorite route', function (): void {
        $passenger = User::factory()->create();
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        FavoriteRoute::factory()->create([
            'user_id' => $passenger->id,
            'route_id' => $route->id,
        ]);

        $this->actingAs($passenger);

        $response = $this->deleteJson("/api/v1/operations/favorites/{$route->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('favorite_routes', [
            'user_id' => $passenger->id,
            'route_id' => $route->id,
        ]);
    });

    it('returns 404 when attempting to delete a route that is not in favorites', function (): void {
        $passenger = User::factory()->create();
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $this->actingAs($passenger);

        $response = $this->deleteJson("/api/v1/operations/favorites/{$route->id}");

        $response->assertNotFound();
    });
});
