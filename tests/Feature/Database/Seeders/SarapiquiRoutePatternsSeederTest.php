<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use Database\Seeders\SarapiquiRoutesSeeder;

describe('Sarapiqui Route Patterns Seeding', function (): void {
    test('creates separate patterns for directions and journey variants', function (): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        $expected = [
            'PV-RF' => [
                'OUTBOUND',
                'INBOUND',
            ],
            'PV-GUA' => [
                'OUTBOUND',
                'INBOUND',
            ],
            'SJ-PV' => [
                'OUTBOUND-VARA-BLANCA',
                'OUTBOUND-ZURQUI',
                'INBOUND-VARA-BLANCA',
                'INBOUND-ZURQUI-DIRECT',
                'INBOUND-ZURQUI-RIO-FRIO',
            ],
        ];

        foreach ($expected as $routeCode => $patternCodes) {
            $route = Route::query()
                ->where('company_id', $company->getKey())
                ->where('code', $routeCode)
                ->sole();

            $actual = RoutePattern::query()
                ->where('route_id', $route->getKey())
                ->pluck('code')
                ->all();

            sort($actual);
            sort($patternCodes);

            expect($actual)->toBe($patternCodes);
        }
    });
});
