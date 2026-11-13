<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE route_fares
                ADD CONSTRAINT route_fares_currency_validity_exclusion
                    EXCLUDE USING gist (
                        route_id WITH =,
                        currency WITH =,
                        daterange(valid_from, valid_until, '[]') WITH &&
                    )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE route_fares
                DROP CONSTRAINT route_fares_currency_validity_exclusion
            SQL);
    }
};
