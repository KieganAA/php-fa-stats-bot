<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One compare-group can carry either:
 *   - 'compare' mode — N≥2 members, side-by-side report (default)
 *   - 'mvt' mode    — exactly 1 member, MVT variant breakdown
 *
 * Stored as a column rather than inferred from member count so a user can
 * pin the mode (e.g. start a comparison with 1 landing while they figure
 * out the second; still gets MVT pulses until rebound).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->string('mode', 16)->default('compare')->after('name');
            $table->index('mode');
        });
    }

    public function down(): void
    {
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
