<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the never-truly-wired user_landing_bindings table (phase B introduced
 * it, phase K removed the command surface). New model: a user has N
 * "compare groups", each containing 1+ tracked landings; every 3h the
 * scheduler fans out a side-by-side report.
 *
 * Why drop+recreate rather than refactor: bindings carried per-landing
 * notify_3h/notify_since_start flags that don't survive into the group
 * world (a group is the unit of notification, not individual members).
 * Easier to start from a clean shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_landing_bindings');

        Schema::create('user_compare_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Human-readable identifier scoped to the user. Auto-generated if
            // the user doesn't pass one ("g1", "g2", …).
            $table->string('name', 64);
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('last_notified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'name']);
            $table->index('paused_at');
        });

        Schema::create('user_compare_group_landings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_compare_group_id')->constrained('user_compare_groups')->cascadeOnDelete();
            $table->foreignId('tracked_landing_id')->constrained('tracked_landings')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['user_compare_group_id', 'tracked_landing_id'], 'ucgl_unique_pair');
            $table->index('tracked_landing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_compare_group_landings');
        Schema::dropIfExists('user_compare_groups');

        Schema::create('user_landing_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tracked_landing_id')->constrained('tracked_landings')->cascadeOnDelete();
            $table->boolean('notify_3h')->default(true);
            $table->boolean('notify_since_start')->default(false);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'tracked_landing_id']);
        });
    }
};
