<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the MySQL-side tables owned by the external auth service.
 *
 * RefreshDatabase only migrates the default (pgsql) connection, so the
 * secondary (mysql) tables are created here per test. DDL cannot be rolled
 * back on most engines, hence create/drop per test, hooked into PHPUnit's
 * lifecycle via setUp/tearDown naming (called from TestCase::setUpTraits).
 *
 * Columns mirror the real smartbus_users schema (users/roles/permissions and
 * Spatie pivots with company_id team key + model_uuid morph key), not the
 * vendor stub defaults.
 */
trait WithMysqlFixture
{
    protected function setUpWithMysqlFixture(): void
    {
        $this->ensureTestingDatabases();
        $this->tearDownWithMysqlFixture();

        Schema::connection('mysql')->create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('mysql')->create('roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('company_id')->nullable()->index();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['company_id', 'name', 'guard_name'], 'roles_company_id_name_guard_name_unique');
        });

        Schema::connection('mysql')->create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::connection('mysql')->create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->index();
            $table->string('model_type');
            $table->uuid('model_uuid')->index();
            $table->uuid('company_id')->nullable()->index();
            $table->index(['model_uuid', 'model_type']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::connection('mysql')->create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id')->index();
            $table->string('model_type');
            $table->uuid('model_uuid')->index();
            $table->uuid('company_id')->nullable()->index();
            $table->index(['model_uuid', 'model_type']);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::connection('mysql')->create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });
    }

    protected function tearDownWithMysqlFixture(): void
    {
        $this->ensureTestingDatabases();
        foreach (['role_has_permissions', 'model_has_permissions', 'model_has_roles', 'permissions', 'roles', 'users'] as $table) {
            Schema::connection('mysql')->dropIfExists($table);
        }
    }
}
