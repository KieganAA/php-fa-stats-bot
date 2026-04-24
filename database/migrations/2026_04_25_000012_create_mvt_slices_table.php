<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mvt_slices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_landing_id')->constrained('tracked_landings')->cascadeOnDelete();
            $table->string('kind', 16); // '3h' | 'since_start'
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->jsonb('rows'); // [{dimensions: {...}, metrics: {clicks: X, ...}}, ...]
            $table->timestampTz('captured_at');

            $table->index(['tracked_landing_id', 'kind', 'window_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mvt_slices');
    }
};
