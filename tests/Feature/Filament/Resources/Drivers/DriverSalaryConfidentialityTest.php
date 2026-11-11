<?php

use App\Enums\UserRole;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ViewDriver;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

describe('Driver Salary Confidentiality', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();
        $this->driver = Driver::factory()->for($this->company)->create([
            'salary_amount' => '987654.32',
            'salary_currency' => 'USD',
            'salary_period' => 'monthly',
        ]);

        $this->authorizeSalaryActor = function (array $permissions): User {
            $actor = createUserWithRole(UserRole::Admin, $this->company);
            CompanyUser::create([
                'company_id' => $this->company->getKey(),
                'user_id' => $actor->getKey(),
            ]);
            grantShield($actor, $permissions, $this->company);
            actingAsInCompany($actor, $this->company);

            return $actor;
        };
    });

    test('keeps salary unavailable in ordinary views and employment forms', function (): void {
        ($this->authorizeSalaryActor)([
            'ViewAny:Driver', 'View:Driver', 'Update:Driver',
        ]);

        Livewire::test(ViewDriver::class, ['record' => $this->driver->getKey()])
            ->assertActionHidden('viewSalary')
            ->assertDontSee('987654.32');

        Livewire::test(EditDriver::class, ['record' => $this->driver->getKey()])
            ->assertActionHidden('manageSalary')
            ->set('data.salary_amount', '1.00')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->driver->fresh()->salary_amount)->toBe('987654.32');
    });

    test('shows the exact authorized salary projection in a deliberate action', function (): void {
        ($this->authorizeSalaryActor)([
            'ViewAny:Driver', 'View:Driver', 'ViewSalary:Driver',
        ]);

        Livewire::test(ViewDriver::class, ['record' => $this->driver->getKey()])
            ->assertActionVisible('viewSalary')
            ->mountAction('viewSalary')
            ->assertActionMounted('viewSalary')
            ->assertSchemaStateSet([
                'salary_amount' => '987654.32',
                'salary_currency' => 'USD',
                'salary_period' => 'monthly',
            ])
            ->assertMountedActionModalSee(__('Confidential Salary'));
    });

    test('updates exact salary values only through the authorized salary action', function (): void {
        ($this->authorizeSalaryActor)([
            'ViewAny:Driver', 'View:Driver', 'Update:Driver', 'ViewSalary:Driver',
        ]);

        Livewire::test(EditDriver::class, ['record' => $this->driver->getKey()])
            ->assertActionVisible('manageSalary')
            ->mountAction('manageSalary')
            ->assertSchemaStateSet([
                'salary_amount' => '987654.32',
                'salary_currency' => 'USD',
                'salary_period' => 'monthly',
            ])
            ->setActionData([
                'salary_amount' => '765432.10',
                'salary_currency' => 'CRC',
                'salary_period' => 'monthly',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $updated = $this->driver->fresh();
        expect($updated->salary_amount)->toBe('765432.10')
            ->and($updated->salary_currency)->toBe('CRC')
            ->and($updated->salary_period)->toBe('monthly');
    });

    test('rejects a forged salary action when salary permission is missing', function (): void {
        ($this->authorizeSalaryActor)([
            'ViewAny:Driver', 'View:Driver', 'Update:Driver',
        ]);

        Livewire::test(EditDriver::class, ['record' => $this->driver->getKey()])
            ->call('mountAction', 'manageSalary')
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->driver->fresh()->salary_amount)->toBe('987654.32');
    });

    test('rejects salary access across company boundaries', function (): void {
        $otherCompany = createCompany();
        $otherDriver = Driver::factory()->create([
            'company_id' => $otherCompany->getKey(),
        ]);
        $actor = ($this->authorizeSalaryActor)([
            'ViewAny:Driver', 'View:Driver', 'ViewSalary:Driver',
        ]);

        expect($otherCompany->getKey())->not->toBe($this->company->getKey())
            ->and($otherDriver->company_id)->toBe($otherCompany->getKey())
            ->and(Gate::forUser($actor)->allows(
                'viewSalary',
                [Driver::class, $this->company, $otherDriver],
            ))->toBeFalse()
            ->and(DriverResource::canViewSalary($otherDriver))->toBeFalse();
    });
});
