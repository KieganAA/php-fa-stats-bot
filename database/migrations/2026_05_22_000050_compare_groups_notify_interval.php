<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase T — each subscription picks its own push cadence.
 *
 * The old behaviour was hard-coded to every 3 hours (cron `0 *​/3 * * *`).
 * Now we run hourly and gate per-group on `last_notified_at + interval <= now`.
 * Default 180 minutes keeps the previous behaviour for existing rows.
 *
 * Range cap (15 min .. 7 days) is enforced at the validator layer; the column
 * itself is just an unsigned int to keep migrations boring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->unsignedInteger('notify_interval_minutes')->default(180)->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->dropColumn('notify_interval_minutes');
        });
    }
};
