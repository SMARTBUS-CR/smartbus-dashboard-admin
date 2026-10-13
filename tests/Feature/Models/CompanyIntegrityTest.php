<?php

use App\Models\Company;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

describe('Company Database Integrity', function (): void {
    test('rolls back both databases when default role creation fails', function (): void {
        DB::connection('mysql')->unprepared("CREATE TRIGGER reject_driver_role BEFORE INSERT ON roles FOR EACH ROW BEGIN IF NEW.name = 'driver' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Default role creation failed'; END IF; END");
        $company = Company::factory()->make();

        try {
            expect(fn () => $company->save())->toThrow(QueryException::class);
            expect(Company::withTrashed()->whereKey($company->id)->exists())->toBeFalse()
                ->and(Role::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(0);
        } finally {
            DB::connection('mysql')->unprepared('DROP TRIGGER IF EXISTS reject_driver_role');
        }
    });

    test('rejects permanent deletion while preserving soft deletion and restoration', function (): void {
        $company = createCompany();
        $company->delete();

        expect(fn () => $company->forceDelete())->toThrow(ValidationException::class);
        $this->assertSoftDeleted($company);
        expect(Role::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(2);
        $company->refresh()->restore();
        $this->assertNotSoftDeleted($company);
    });
});
