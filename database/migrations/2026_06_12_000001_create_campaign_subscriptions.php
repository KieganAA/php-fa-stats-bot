<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase BB — campaign subscriptions.
 *
 * The bot's new core: a user points at an AIO campaign and we auto-derive the
 * interesting things to watch — traffic splits (a funnel step with 2+ landings)
 * and MVT landings (a landing with 2+ variants in any field). Each of those
 * derived watch-targets is a child `user_compare_groups` row (compare mode for
 * splits, mvt mode for MVT landings), linked back here by
 * `campaign_subscription_id`.
 *
 * This parent row carries the campaign identity + sync bookkeeping. The actual
 * push fan-out still happens at the child-group granularity (reusing the
 * existing scheduler), but the parent's `paused_at` gates all its children and
 * `notify_interval_minutes` is the default new children inherit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // AIO campaign identity. uuid is the stable key we fetch by;
            // human_id + name are cached for display so a list render doesn't
            // need an AIO round-trip.
            $table->uuid('campaign_uuid');
            $table->unsignedBigInteger('campaign_human_id')->nullable();
            $table->string('campaign_name', 191)->default('');
            $table->json('countries')->nullable();

            // Default cadence new child groups inherit. Children keep their own
            // copy so a user can later retune one split without touching the rest.
            $table->unsignedInteger('notify_interval_minutes')->default(180);

            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            // One subscription per (user, campaign) — re-subscribing resyncs in
            // place rather than duplicating.
            $table->unique(['user_id', 'campaign_uuid']);
            $table->index('paused_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_subscriptions');
    }
};
