<?php

namespace App\Services\Campaign;

use App\Models\Aio\Landing;
use App\Models\CampaignSubscription;
use App\Models\TrackedLanding;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Models\UserCompareGroupLanding;
use App\Services\Aio\Dto\CampaignStructure;
use App\Services\Campaign\Dto\CampaignAnalysis;
use App\Services\Campaign\Dto\MvtDescriptor;
use App\Services\Campaign\Dto\ResyncResult;
use App\Services\Campaign\Dto\SplitDescriptor;
use App\Services\Campaign\Exceptions\EmptyCampaignException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns an AIO campaign into a set of child compare/MVT subscriptions and keeps
 * them in sync with the campaign's evolving structure.
 *
 * create() and resync() share one engine: fetch → analyze → reconcile. The
 * only difference is create() also (idempotently) makes the parent
 * CampaignSubscription row first.
 *
 * Reconciliation is keyed on UserCompareGroup.child_key:
 *   - "split:{stepUuid}"   one compare child per traffic split
 *   - "mvt:{landingUuid}"  one mvt child per MVT landing
 * Desired set (from analysis) is diffed against existing children:
 *   - new key           → create child
 *   - existing key,
 *     membership changed → update members in place
 *   - existing key, was
 *     orphaned, reappears→ clear orphaned_at (reactivate)
 *   - existing key gone  → mark orphaned_at (report & wait — never auto-delete)
 */
final class CampaignSubscriptionService
{
    public function __construct(
        private readonly CampaignStructureFetcher $structures,
        private readonly LandingMvtFetcher $mvt,
        private readonly CampaignAnalyzer $analyzer,
    ) {}

    /**
     * Subscribe a user to a campaign (or resync if they already are).
     * $campaignUuid must be a resolved AIO campaign uuid.
     */
    public function create(User $user, string $campaignUuid): ResyncResult
    {
        $analysis = $this->fetchAndAnalyze($campaignUuid);
        $structure = $analysis->structure;

        // Nothing to track and not already subscribed → refuse. Creating a
        // parent with zero children just makes a dead row that never pushes.
        // (If they're ALREADY subscribed, fall through so resync can orphan the
        // vanished children rather than silently leaving stale ones.)
        $exists = CampaignSubscription::query()
            ->where('user_id', $user->id)
            ->where('campaign_uuid', $campaignUuid)
            ->exists();
        if (! $exists && $analysis->isEmpty()) {
            $label = $structure->humanId !== null ? "#{$structure->humanId}" : 'эта кампания';
            // Spell out what the analyzer saw per step — "6 rows in the AIO UI
            // but 0 splits" is almost always disabled toggles or the same
            // landing repeated for weight rotation, and that should be visible
            // right in the refusal instead of needing a debugging session.
            $detail = $structure->steps !== []
                ? ' Структура: '.implode('; ', $structure->describeSteps()).'.'
                : ' В шагах кампании нет активных лендов.';
            throw new EmptyCampaignException(
                "В кампании {$label} нет ни сплитов, ни MVT — подписываться не на что.".$detail,
            );
        }

        $subscription = CampaignSubscription::query()->updateOrCreate(
            ['user_id' => $user->id, 'campaign_uuid' => $campaignUuid],
            [
                'campaign_human_id' => $structure->humanId,
                'campaign_name' => mb_substr($structure->name, 0, 191),
                'countries' => $structure->countries,
                'last_synced_at' => CarbonImmutable::now(),
            ],
        );

        return $this->reconcile($subscription, $analysis);
    }

    /** Re-derive children for an existing subscription. */
    public function resync(CampaignSubscription $subscription): ResyncResult
    {
        $analysis = $this->fetchAndAnalyze($subscription->campaign_uuid);
        $structure = $analysis->structure;

        // Refresh cached identity in case the campaign was renamed / re-geo'd.
        $subscription->campaign_human_id = $structure->humanId;
        $subscription->campaign_name = mb_substr($structure->name, 0, 191);
        $subscription->countries = $structure->countries;
        $subscription->last_synced_at = CarbonImmutable::now();
        $subscription->save();

        return $this->reconcile($subscription, $analysis);
    }

    private function fetchAndAnalyze(string $campaignUuid): CampaignAnalysis
    {
        $structure = $this->structures->fetch($campaignUuid);
        $mvtInfo = $this->mvt->fetchMany($structure->allLandingUuids());

        return $this->analyzer->analyze($structure, $mvtInfo);
    }

    private function reconcile(CampaignSubscription $subscription, CampaignAnalysis $analysis): ResyncResult
    {
        $structure = $analysis->structure;

        // Desired children keyed by child_key.
        $desired = [];
        foreach ($analysis->splits as $split) {
            $desired[$this->splitKey($split)] = ['type' => 'split', 'descriptor' => $split];
        }
        foreach ($analysis->mvts as $mvtDesc) {
            $desired[$this->mvtKey($mvtDesc)] = ['type' => 'mvt', 'descriptor' => $mvtDesc];
        }

        $created = [];
        $updated = [];
        $reactivated = [];
        $orphaned = [];

        DB::transaction(function () use ($subscription, $structure, $desired, &$created, &$updated, &$reactivated, &$orphaned): void {
            /** @var array<string, UserCompareGroup> $existing */
            $existing = $subscription->children()->with('members')->get()->keyBy('child_key')->all();

            // Upsert desired children.
            foreach ($desired as $key => $spec) {
                $child = $existing[$key] ?? null;
                if ($child === null) {
                    $created[] = $this->createChild($subscription, $structure, $spec);

                    continue;
                }

                $wasOrphan = $child->orphaned_at !== null;
                $membersChanged = $this->syncChildMembers($child, $spec);

                if ($wasOrphan) {
                    $child->orphaned_at = null;
                    $child->save();
                    $reactivated[] = $child;
                } elseif ($membersChanged) {
                    $updated[] = $child;
                }
            }

            // Anything existing but no longer desired → mark orphaned (don't
            // delete). Skip already-orphaned ones so they're reported only once.
            foreach ($existing as $key => $child) {
                if (isset($desired[$key])) {
                    continue;
                }
                if ($child->orphaned_at !== null) {
                    continue;
                }
                $child->orphaned_at = CarbonImmutable::now();
                $child->save();
                $orphaned[] = $child;
            }
        });

        return new ResyncResult($created, $updated, $reactivated, $orphaned, $structure);
    }

    /**
     * @param  array{type: string, descriptor: SplitDescriptor|MvtDescriptor}  $spec
     */
    private function createChild(CampaignSubscription $subscription, CampaignStructure $structure, array $spec): UserCompareGroup
    {
        $descriptor = $spec['descriptor'];
        $isSplit = $spec['type'] === 'split';

        $child = new UserCompareGroup;
        $child->user_id = $subscription->user_id;
        $child->campaign_subscription_id = $subscription->id;
        $child->mode = $isSplit ? UserCompareGroup::MODE_COMPARE : UserCompareGroup::MODE_MVT;
        $child->step_position = $descriptor->stepPosition;
        $child->child_key = $isSplit ? $this->splitKey($descriptor) : $this->mvtKey($descriptor);
        $child->name = $this->childName($subscription, $spec);
        // updateOrCreate doesn't hydrate the DB default back into the model, so
        // fall back to the constant when the parent's interval isn't set.
        $child->notify_interval_minutes = $subscription->notify_interval_minutes
            ?? CampaignSubscription::DEFAULT_INTERVAL_MINUTES;
        $child->save();

        $this->syncChildMembers($child, $spec);

        return $child;
    }

    /**
     * Make the child's members match the descriptor. Returns true if anything
     * changed. For MVT children there's exactly one member (the landing); for
     * splits, the landings of that step in order.
     *
     * @param  array{type: string, descriptor: SplitDescriptor|MvtDescriptor}  $spec
     */
    private function syncChildMembers(UserCompareGroup $child, array $spec): bool
    {
        $descriptor = $spec['descriptor'];
        $position = $descriptor->stepPosition;

        $landingUuids = $spec['type'] === 'split'
            ? $descriptor->landingUuids
            : [$descriptor->landingUuid];

        // Current member landing uuids (ordered).
        $current = $child->members()
            ->with('trackedLanding')
            ->get()
            ->map(fn ($m) => $m->trackedLanding?->landing_uuid)
            ->filter()
            ->values()
            ->all();

        if ($current === $landingUuids) {
            return false;
        }

        // Rebuild membership: simplest correct approach. tracked_landings rows
        // are shared (firstOrCreate), so this just rewires the join table.
        $child->members()->delete();
        foreach (array_values($landingUuids) as $i => $uuid) {
            $tracked = TrackedLanding::query()->firstOrCreate(
                ['landing_uuid' => $uuid, 'position' => $position],
                ['tracking_started_at' => CarbonImmutable::now(), 'paused_at' => null],
            );
            if ($tracked->paused_at !== null) {
                $tracked->paused_at = null;
                $tracked->save();
            }
            UserCompareGroupLanding::create([
                'user_compare_group_id' => $child->id,
                'tracked_landing_id' => $tracked->id,
                'sort_order' => $i,
            ]);
        }

        return true;
    }

    /**
     * Human-facing child name. Per the chosen convention:
     *   split → "#116400 CA · шаг 1 сплит"
     *   mvt   → "#116400 CA · MVT #221674"
     *
     * @param  array{type: string, descriptor: SplitDescriptor|MvtDescriptor}  $spec
     */
    private function childName(CampaignSubscription $subscription, array $spec): string
    {
        $prefix = $subscription->shortLabel(); // "#116400 CA"
        $descriptor = $spec['descriptor'];

        if ($spec['type'] === 'split') {
            return mb_substr("{$prefix} · шаг {$descriptor->stepPosition} сплит", 0, 64);
        }

        // MVT — try to show the landing's human_id for readability.
        $landing = Landing::query()->where('uuid', $descriptor->landingUuid)->first();
        $lid = $landing?->human_id !== null ? "#{$landing->human_id}" : mb_substr($descriptor->landingUuid, 0, 8);

        return mb_substr("{$prefix} · MVT {$lid}", 0, 64);
    }

    private function splitKey(SplitDescriptor $split): string
    {
        return UserCompareGroup::CHILD_SPLIT.':'.$split->stepUuid;
    }

    private function mvtKey(MvtDescriptor $mvt): string
    {
        return UserCompareGroup::CHILD_MVT.':'.$mvt->landingUuid;
    }
}
