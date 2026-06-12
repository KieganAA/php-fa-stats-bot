<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second schedule mode for notifications. Until now the only knob was
 * notify_interval_minutes ("every N hours"); this adds "daily at HH:MM"
 * (interpreted in the owning user's timezone).
 *
 * Lives on BOTH tables, mirroring how the interval works: the campaign row is
 * what the user edits, the child compare groups are what the cron tick reads —
 * the controller copies schedule changes down to the children.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_subscriptions', function (Blueprint $table) {
            $table->string('schedule_type', 16)->default('interval');
            $table->string('daily_at', 5)->nullable(); // 'HH:MM' user-local
        });
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->string('schedule_type', 16)->default('interval');
            $table->string('daily_at', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['schedule_type', 'daily_at']);
        });
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->dropColumn(['schedule_type', 'daily_at']);
        });
    }
};
