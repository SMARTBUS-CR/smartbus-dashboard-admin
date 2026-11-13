<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE route_fares
                ADD CONSTRAINT route_fares_non_negative_amount_check
                    CHECK (amount >= 0),
                ADD CONSTRAINT route_fares_supported_currency_check
                    CHECK (
                        currency IN (
                            'BZD', 'CRC', 'GTQ', 'HNL',
                            'NIO', 'PAB', 'USD'
                        )
                    ),
                ADD CONSTRAINT route_fares_validity_order_check
                    CHECK (
                        valid_from IS NULL
                        OR valid_until IS NULL
                        OR valid_until >= valid_from
                    )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE route_fares
                DROP CONSTRAINT route_fares_non_negative_amount_check,
                DROP CONSTRAINT route_fares_supported_currency_check,
                DROP CONSTRAINT route_fares_validity_order_check
            SQL);
    }
};
