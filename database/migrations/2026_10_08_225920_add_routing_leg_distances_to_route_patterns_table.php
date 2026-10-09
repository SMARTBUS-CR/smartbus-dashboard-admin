<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_patterns', function (Blueprint $table): void {
            $table->jsonb('routing_leg_distances')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('route_patterns', function (Blueprint $table): void {
            $table->dropColumn('routing_leg_distances');
        });
    }
};
