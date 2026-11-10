<?php

use App\Enums\CompanyStatus;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;

test('hides route catalogue endpoints when their company is unavailable', function (string $state): void {
    $company = createCompany();
    $route = Route::factory()->for($company)->create();
    $pattern = RoutePattern::factory()->for($route)->create();
    RouteSchedule::factory()->for($pattern, 'pattern')->create();
    RouteFare::factory()->for($route)->create();
    $visible = Route::factory()->create();
    if ($state === 'archived') {
        $company->delete();
    } else {
        $company->update(['status' => CompanyStatus::INACTIVE]);
    }

    $response = $this->getJson('/api/v1/operations/routes')->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$visible->id]);
    foreach ([
        '/routes/'.$route->id,
        '/routes/'.$route->id.'/patterns',
        '/routes/'.$route->id.'/fares',
        '/route-patterns/'.$pattern->id,
        '/route-patterns/'.$pattern->id.'/schedules?date=2026-11-09',
    ] as $path) {
        $this->getJson('/api/v1/operations'.$path)->assertNotFound();
    }
})->with(['inactive', 'archived']);

test('hides patterns schedules and fares beneath an archived route', function (): void {
    $route = Route::factory()->create();
    $pattern = RoutePattern::factory()->for($route)->create();
    RouteSchedule::factory()->for($pattern, 'pattern')->create();
    RouteFare::factory()->for($route)->create();
    $route->delete();

    foreach ([
        '/routes/'.$route->id,
        '/routes/'.$route->id.'/patterns',
        '/routes/'.$route->id.'/fares',
        '/route-patterns/'.$pattern->id,
        '/route-patterns/'.$pattern->id.'/schedules',
    ] as $path) {
        $this->getJson('/api/v1/operations'.$path)->assertNotFound();
    }
});

test('keeps archived patterns out of route relationships and rejects direct access', function (): void {
    $route = Route::factory()->create();
    $visible = RoutePattern::factory()->for($route)->create();
    $archived = RoutePattern::factory()->for($route)->create();
    $archived->delete();

    $response = $this->getJson('/api/v1/operations/routes/'.$route->id.'/patterns')->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$visible->id]);
    $detail = $this->getJson('/api/v1/operations/routes/'.$route->id)->assertOk();
    expect(collect($detail->json('data.relationships.patterns.data'))->pluck('id')->all())->toBe([$visible->id]);
    $this->getJson('/api/v1/operations/route-patterns/'.$archived->id)->assertNotFound();
    $this->getJson('/api/v1/operations/route-patterns/'.$archived->id.'/schedules')->assertNotFound();
});

test('hides private stops of unavailable companies while retaining public shared stops', function (string $state): void {
    $company = createCompany();
    $hidden = Stop::factory()->for($company)->create();
    $visible = Stop::factory()->create();
    $shared = Stop::factory()->shared()->create();
    if ($state === 'archived') {
        $company->delete();
    } else {
        $company->update(['status' => CompanyStatus::INACTIVE]);
    }

    $response = $this->getJson('/api/v1/operations/stops')->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toEqualCanonicalizing([$visible->id, $shared->id]);
    $this->getJson('/api/v1/operations/stops/'.$hidden->id)->assertNotFound();
    $this->getJson('/api/v1/operations/stops/'.$shared->id)->assertOk();
})->with(['inactive', 'archived']);

test('company stop filters include only its private stops and shared stops used by available patterns', function (): void {
    $company = createCompany();
    $otherCompany = createCompany();
    $route = Route::factory()->for($company)->create();
    $pattern = RoutePattern::factory()->for($route)->create();
    $private = Stop::factory()->for($company)->create(['name' => 'Central private']);
    $secondary = Stop::factory()->for($company)->create(['name' => 'Secondary private']);
    $shared = Stop::factory()->shared()->create(['name' => 'Central shared']);
    RoutePatternStop::factory()->for($pattern, 'pattern')->for($shared)->create(['stop_sequence' => 1]);
    RoutePatternStop::factory()->for($pattern, 'pattern')->for($shared)->create(['stop_sequence' => 2]);
    Stop::factory()->shared()->create(['name' => 'Central unused']);
    $otherPrivate = Stop::factory()->for($otherCompany)->create(['name' => 'Central other private']);
    $otherPattern = RoutePattern::factory()->for(Route::factory()->for($otherCompany))->create();
    $otherShared = Stop::factory()->shared()->create(['name' => 'Central other shared']);
    RoutePatternStop::factory()->for($otherPattern, 'pattern')->for($otherShared)->create();
    $archivedPattern = RoutePattern::factory()->for($route)->create();
    $archivedShared = Stop::factory()->shared()->create(['name' => 'Central archived pattern']);
    RoutePatternStop::factory()->for($archivedPattern, 'pattern')->for($archivedShared)->create();
    $archivedPattern->delete();
    $archivedRoute = Route::factory()->for($company)->create();
    $archivedRoutePattern = RoutePattern::factory()->for($archivedRoute)->create();
    $archivedRouteStop = Stop::factory()->shared()->create(['name' => 'Central archived route']);
    RoutePatternStop::factory()->for($archivedRoutePattern, 'pattern')->for($archivedRouteStop)->create();
    $archivedRoute->delete();

    $uri = '/api/v1/operations/stops?filter[company_id]='.$company->id;
    $response = $this->getJson($uri)->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toEqualCanonicalizing([$private->id, $secondary->id, $shared->id]);
    $filtered = $this->getJson($uri.'&filter[name]=Central')->assertOk();
    expect(collect($filtered->json('data'))->pluck('id')->all())->toEqualCanonicalizing([$private->id, $shared->id]);
    $multiCompany = $this->getJson($uri.','.$otherCompany->id)->assertOk();
    expect(collect($multiCompany->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing([$private->id, $secondary->id, $shared->id, $otherPrivate->id, $otherShared->id]);
});

test('unavailable companies do not regain shared stop visibility through a company filter', function (): void {
    $company = createCompany();
    $pattern = RoutePattern::factory()->for(Route::factory()->for($company))->create();
    $shared = Stop::factory()->shared()->create();
    RoutePatternStop::factory()->for($pattern, 'pattern')->for($shared)->create();
    Stop::factory()->for($company)->create();
    $company->update(['status' => CompanyStatus::INACTIVE]);

    $this->getJson('/api/v1/operations/stops?filter[company_id]='.$company->id)->assertOk()
        ->assertJsonPath('data', []);
});
