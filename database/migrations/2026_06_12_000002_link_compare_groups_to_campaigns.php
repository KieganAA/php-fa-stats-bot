<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase BB — wire compare groups to their parent campaign subscription.
 *
 * A compare group can now be one of two things:
 *   - Standalone (campaign_subscription_id = null) — the old hand-built
 *     compare/MVT subscription. Unchanged.
 *   - Campaign-derived (campaign_subscription_id set) — auto-created by the
 *     resync logic from an AIO campaign's structure.
 *
 * `child_key` is the stable identity used to make resync idempotent:
 *   - "split:{stepUuid}"   for a traffic split at a funnel step
 *   - "mvt:{landingUuid}"  for an MVT landing
 * Diffing desired-vs-existing by this key lets resync add/update/orphan
 * children without churning rows that didn't change.
 *
 * `step_position` is the 1-indexed funnel step (for labelling only).
 *
 * `orphaned_at` supports the "report and wait" resync policy: when a split/MVT
 * disappears from the campaign we mark the child orphaned and tell the user,
 * rather than silently deleting. A set `orphaned_at` means "no longer in AIO
 * structure, awaiting the user's keep/delete decision" — it's cleared if the
 * split reappears on a later resync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->foreignId('campaign_subscription_id')
                ->nullable()
                ->after('user_id')
                ->constrained('campaign_subscriptions')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('step_position')->nullable()->after('mode');
            $table->string('child_key', 128)->nullable()->after('step_position');
            $table->timestampTz('orphaned_at')->nullable()->after('last_notified_at');

            // Idempotent resync upserts on (subscription, child_key).
            $table->unique(['campaign_subscription_id', 'child_key'], 'ucg_campaign_child_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_compare_groups', function (Blueprint $table) {
            $table->dropUnique('ucg_campaign_child_unique');
            $table->dropConstrainedForeignId('campaign_subscription_id');
            $table->dropColumn(['step_position', 'child_key', 'orphaned_at']);
        });
    }
};
