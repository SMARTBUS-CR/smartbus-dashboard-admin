<?php

use App\Http\Controllers\Api\V1\Operations\DriverProfileController;
use App\Http\Controllers\Api\V1\Operations\DriverTripController;
use App\Http\Controllers\Api\V1\Operations\PublicTripController;
use App\Http\Controllers\Api\V1\Operations\RouteController;
use App\Http\Controllers\Api\V1\Operations\RouteFareController;
use App\Http\Controllers\Api\V1\Operations\RoutePatternController;
use App\Http\Controllers\Api\V1\Operations\StopController;
use App\Http\Controllers\Api\V1\Operations\TrackingContextController;
use App\Http\Controllers\Api\V1\Operations\TripExecutionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operations API Routes (SmartBus Global)
|--------------------------------------------------------------------------
|
| Prefix: /api/v1/operations
| All responses conform to the JSON:API standard.
|
*/

Route::prefix('v1/operations')->group(function (): void {
    // Routes Catalogue
    Route::get('routes', [RouteController::class, 'index'])->name('operations.routes.index');
    Route::get('routes/{id}', [RouteController::class, 'show'])->whereUuid('id')->name('operations.routes.show');
    Route::get('routes/{id}/patterns', [RouteController::class, 'patterns'])->whereUuid('id')->name('operations.routes.patterns');
    Route::get('routes/{id}/fares', [RouteFareController::class, 'index'])->whereUuid('id')->name('operations.routes.fares');

    // Route Patterns & Schedules
    Route::get('route-patterns/{id}', [RoutePatternController::class, 'show'])->whereUuid('id')->name('operations.route-patterns.show');
    Route::get('route-patterns/{id}/schedules', [RoutePatternController::class, 'schedules'])->whereUuid('id')->name('operations.route-patterns.schedules');
    Route::get('route-patterns/{id}/trips', [PublicTripController::class, 'index'])->whereUuid('id')->name('operations.route-patterns.trips');
    Route::get('public/trips/{id}', [PublicTripController::class, 'show'])->whereUuid('id')->name('operations.public.trips.show');

    // Stops Catalogue
    Route::get('stops', [StopController::class, 'index'])->name('operations.stops.index');
    Route::get('stops/{id}', [StopController::class, 'show'])->whereUuid('id')->name('operations.stops.show');

    // Driver Profiles, Assigned Trips & Trip Execution (Authenticated)
    Route::middleware('auth.api')->group(function (): void {
        Route::get('driver/profiles', [DriverProfileController::class, 'index'])->name('operations.driver.profiles');
        Route::get('driver/trips', [DriverTripController::class, 'index'])->name('operations.driver.trips');

        Route::get('trips/{id}', [TripExecutionController::class, 'show'])->whereUuid('id')->name('operations.trips.show');
        Route::post('trips/{id}/start', [TripExecutionController::class, 'start'])->whereUuid('id')->name('operations.trips.start');
        Route::post('trips/{id}/complete', [TripExecutionController::class, 'complete'])->whereUuid('id')->name('operations.trips.complete');
    });

    // Telemetry Tracking Context (for GPS Service)
    Route::get('trips/{id}/tracking-context', [TrackingContextController::class, 'show'])
        ->middleware('auth.gps')
        ->whereUuid('id')
        ->name('operations.trips.tracking-context');
});

// Internal Service Routes (GPS & ETA microservices)
Route::prefix('internal/v1')->middleware('auth.gps')->group(function (): void {
    Route::get('trips/{id}/tracking-context', [TrackingContextController::class, 'show'])
        ->whereUuid('id')
        ->name('internal.trips.tracking-context');
});
