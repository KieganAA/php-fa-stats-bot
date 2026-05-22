<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic aggregate snapshots of a tracked landing. One row per (tracked
 * landing, window). The MVT-level breakdown lives in mvt_slices — this table
 * is the lighter view ("just the totals").
 *
 * kind:
 *   - '3h'           rolling last 3 hours, captured by the scheduler
 *   - 'since_start'  tracking_started_at → now, for "is the campaign improving?"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_landing_id')->constrained('tracked_landings')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->jsonb('metrics'); // {clicks: N, lp_ctr: 0.45, ...}
            $table->timestampTz('captured_at');

            $table->index(['tracked_landing_id', 'kind', 'window_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_snapshots');
    }
};
