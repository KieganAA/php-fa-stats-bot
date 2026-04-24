<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_landings', function (Blueprint $table) {
            $table->id();
            $table->string('landing_uuid', 64);
            $table->unsignedTinyInteger('position');
            $table->timestampTz('tracking_started_at');
            $table->timestampTz('paused_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['landing_uuid', 'position']);
            $table->index('paused_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_landings');
    }
};
