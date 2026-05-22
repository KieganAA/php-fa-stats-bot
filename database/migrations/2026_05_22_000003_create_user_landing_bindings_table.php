<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user interest in a tracked landing. Multiple users can bind to the same
 * tracked_landing (they'd get separate notifications, but the AIO query runs
 * once per cycle — that's the whole point of having tracked_landings be
 * shared).
 *
 * notify_3h / notify_since_start are independent toggles so a user can watch
 * a landing without getting pinged every 3 hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_landing_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tracked_landing_id')->constrained('tracked_landings')->cascadeOnDelete();
            $table->boolean('notify_3h')->default(true);
            $table->boolean('notify_since_start')->default(false);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'tracked_landing_id']);
            $table->index(['tracked_landing_id', 'notify_3h']);
            $table->index(['tracked_landing_id', 'notify_since_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_landing_bindings');
    }
};
