<?php

use App\Models\RouteSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_schedule_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(RouteSchedule::class)
                ->constrained('route_schedules')
                ->restrictOnDelete();

            $table->date('service_date');

            $table->timestamps();

            $table->unique(['route_schedule_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_schedule_exceptions');
    }
};
