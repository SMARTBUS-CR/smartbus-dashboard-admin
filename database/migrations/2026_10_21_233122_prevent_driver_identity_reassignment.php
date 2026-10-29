<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_driver_identity_reassignment() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id THEN
                    RAISE EXCEPTION 'drivers_identity_immutable: Driver employment identity is immutable.'
                        USING ERRCODE = '23514', CONSTRAINT = 'drivers_identity_immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER drivers_identity_immutable
                BEFORE UPDATE OF company_id, user_id ON drivers
                FOR EACH ROW EXECUTE FUNCTION prevent_driver_identity_reassignment();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS drivers_identity_immutable ON drivers;
            DROP FUNCTION IF EXISTS prevent_driver_identity_reassignment();
            SQL);
    }
};
