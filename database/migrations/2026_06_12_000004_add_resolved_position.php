<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache of the REAL funnel position (landing_uuids[N]) a campaign child's
 * landings receive traffic at. AIO's settings-dict step order does NOT
 * reliably map to the analytics LP position — they can even be reversed — so
 * the structure-derived step_position is only a starting guess. The push
 * renderer detects the true position from analytics once and stores it here;
 * thereafter it queries that position directly. NULL = not yet detected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('resolved_position')->nullable()->after('step_position');
        });
    }

    public function down(): void
    {
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->dropColumn('resolved_position');
        });
    }
};
