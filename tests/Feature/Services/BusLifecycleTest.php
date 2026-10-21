<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Services\BusManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

describe('Bus Lifecycle', function (): void {
    test('archives a company bus for an authorized administrator', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Delete:Bus'], $company);

        $bus = Bus::factory()->for($company)->active()->create();

        $this->assertModelExists($bus);

        $archived = app(BusManagementService::class)->archive(
            $actor,
            $company,
            $bus->getKey(),
        );

        expect($archived->getKey())->toBe($bus->getKey())
            ->and($archived->company_id)->toBe($company->getKey())
            ->and($archived->trashed())->toBeTrue();

        $this->assertSoftDeleted('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
        ]);
    });
    test('rejects active state changes for an archived bus', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $bus = Bus::factory()->for($company)->archived()->create();

        $this->assertSoftDeleted($bus);

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected a model not found exception.');
        } catch (ModelNotFoundException $exception) {
            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $company->getKey(),
                'is_active' => true,
            ]);

            $this->assertSoftDeleted($bus);
        }
    });
    test('rejects state changes for an archived company even for a super admin', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $bus = Bus::factory()->for($company)->active()->create();

        $company->delete();

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('company');

            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $company->getKey(),
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    });
    test('rejects state changes for an inactive company even for a super admin', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $bus = Bus::factory()->for($company)->active()->create();

        $company->update([
            'status' => CompanyStatus::INACTIVE,
        ]);

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('company');

            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $company->getKey(),
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    });
    test('hides another company bus when changing its active state', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Update:Bus'], $company);

        $bus = Bus::factory()->for($otherCompany)->active()->create();

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected a model not found exception.');
        } catch (ModelNotFoundException $exception) {
            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $otherCompany->getKey(),
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    });
    test('rejects state changes when the company admin lacks permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        $bus = Bus::factory()->for($company)->active()->create();

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected an authorization exception.');
        } catch (AuthorizationException $exception) {
            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $company->getKey(),
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    });
    test('reactivates a company bus for an authorized administrator', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Update:Bus'], $company);

        $bus = Bus::factory()->for($company)->inactive()->create();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'is_active' => false,
        ]);

        $updated = app(BusManagementService::class)->setActive(
            $actor,
            $company,
            $bus->getKey(),
            true,
        );

        expect($updated->getKey())->toBe($bus->getKey())
            ->and($updated->is_active)->toBeTrue();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
            'is_active' => true,
            'deleted_at' => null,
        ]);
    });
    test('deactivates a company bus for an authorized administrator', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Update:Bus'], $company);

        $bus = Bus::factory()->for($company)->active()->create();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'is_active' => true,
        ]);

        $updated = app(BusManagementService::class)->setActive(
            $actor,
            $company,
            $bus->getKey(),
            false,
        );

        expect($updated->getKey())->toBe($bus->getKey())
            ->and($updated->is_active)->toBeFalse();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
            'is_active' => false,
            'deleted_at' => null,
        ]);
    });
});
