<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-subscription digest window. Until now every scheduled push reported the
 * current day ("today"); this lets a subscription report a different span —
 * "yesterday", "last week", etc. — while the schedule keeps controlling only
 * how OFTEN it fires.
 *
 * Nullable, null ⇒ "today" (backward-compatible for existing rows). Lives on
 * BOTH tables like schedule_type: the campaign row is what the user edits, the
 * child compare groups are what the cron tick reads — the controller copies it
 * down. Standalone tracking groups read their own column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_subscriptions', function (Blueprint $table) {
            $table->string('report_period', 32)->nullable();
        });
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->string('report_period', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_subscriptions', function (Blueprint $table) {
            $table->dropColumn('report_period');
        });
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->dropColumn('report_period');
        });
    }
};
