<?php

namespace Tests\Feature\Campaign;

use App\Jobs\NotifyCampaignJob;
use App\Models\CampaignSubscription;
use App\Models\User;
use App\Services\Campaign\CampaignSubscriptionService;
use App\Services\Stats\PeriodParser;
use App\Services\Tracking\GroupReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * The campaign digest: one Telegram message per campaign per tick. Sections
 * with traffic render tables; zero-traffic sections collapse to one line; a
 * fully silent campaign shrinks to a single "нет трафика" one-liner. Either
 * way last_notified_at advances so the schedule doesn't re-fire every tick.
 */
final class NotifyCampaignJobTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPAIGN_UUID = '967815b9-6c7a-4bf9-8bbe-005f3d188ab2';

    /** @var array<string, list<string>> */
    private array $steps = [];

    /** @var list<string> landing uuids whose pivot rows return traffic */
    private array $trafficUuids = [];

    /** When set, pivot returns data ONLY for this landing_uuids[N] position. */
    private ?int $trafficPosition = null;

    /** When set, pivot at this position returns AIO's 422 "Wrong query". */
    private ?int $errorPosition = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('aio.base_url', 'https://app.aio.test');
        config()->set('aio.token', 'test-token');
        config()->set('aio.tenant_id', 'tenant-1');
        config()->set('aio.cache.enabled', false);
        config()->set('aio.limiter.max_wait_ms', 500);
        config()->set('aio.limiter.retry_interval_ms', 20);
        config()->set('aio.rate_limits.per_minute', 1000);

        Redis::connection()->flushdb();
        $this->seedTargetMetrics();

        Http::fake([
            'app.aio.test/api/v1/actions/data*' => function ($request) {
                $body = json_decode($request->body(), true) ?: [];
                if (($body['action'] ?? '') === 'Campaign\\Create') {
                    return Http::response($this->campaignEnvelope($this->steps));
                }

                return Http::response($this->landerEnvelope($body['uuids'][0] ?? ''));
            },
            // Pivot: AIO returns a TREE that PivotWalker flattens — each child
            // node carries group_* dimensions + metric scalars. Emit nodes only
            // for landings flagged as having traffic.
            'app.aio.test/api/v1/pivot-report/data*' => function ($request) {
                $body = json_decode($request->body(), true) ?: [];
                $conditions = collect($body['conditions'] ?? []);
                // Position the query filters on (landing_uuids[N]).
                $landingCond = $conditions->first(fn ($c) => str_starts_with((string) ($c['key'] ?? ''), 'landing_uuids['));
                $pos = $landingCond ? (int) preg_replace('/\D/', '', $landingCond['key']) : null;
                if ($this->errorPosition !== null && $pos === $this->errorPosition) {
                    // AIO rejects landing_uuids[N] beyond the funnel depth.
                    return Http::response(['error' => 'Wrong query', 'trace' => []], 422);
                }
                if ($this->trafficPosition !== null && $pos !== $this->trafficPosition) {
                    return Http::response([]); // no data at this position
                }

                $values = $conditions->flatMap(fn ($c) => $c['values'] ?? [])->all();
                $tree = [];
                foreach ($values as $uuid) {
                    if (in_array($uuid, $this->trafficUuids, true)) {
                        $tree[] = ['group_0' => $uuid, 'clicks-uuid' => 100];
                    }
                }

                return Http::response($tree);
            },
        ]);
    }

    public function test_digest_is_one_message_with_empty_sections_collapsed(): void
    {
        // Two splits: step-1 has traffic, step-2 doesn't.
        $this->steps = ['step-1' => ['lp-a', 'lp-b'], 'step-2' => ['lp-c', 'lp-d']];
        $this->trafficUuids = ['lp-a', 'lp-b'];
        [$user, $sub] = $this->subscribe();

        $bot = Nutgram::fake();
        (new NotifyCampaignJob($user->id, $sub->id))
            ->handle($bot, app(GroupReportRenderer::class), app(PeriodParser::class));

        $bot->assertCalled('sendMessage', 1); // ONE digest, not per-child spam
        $bot->assertRaw(function ($request) {
            $text = self::messageText($request);

            return str_contains($text, 'шаг 1 сплит')      // section with table
                && str_contains($text, '#8001')             // landing column
                && str_contains($text, '😴')                 // collapsed section
                && str_contains($text, 'шаг 2 сплит');
        });

        foreach ($sub->children()->get() as $child) {
            $this->assertNotNull($child->last_notified_at, 'every child stamped');
        }
    }

    public function test_self_heals_inverted_funnel_position_and_caches_it(): void
    {
        // Structure says step-1 landings sit at LP1, but analytics attributes
        // their traffic to LP2 (AIO dict order ≠ funnel order). The digest must
        // still find the data by probing positions, and cache the result.
        $this->steps = ['step-1' => ['lp-a', 'lp-b']];
        $this->trafficUuids = ['lp-a', 'lp-b'];
        $this->trafficPosition = 2; // data lives at LP2, not the structure's LP1
        [$user, $sub] = $this->subscribe();

        $child = $sub->children()->firstOrFail();
        $this->assertSame(1, $child->step_position);     // structure guess
        $this->assertNull($child->resolved_position);    // not yet detected

        $bot = Nutgram::fake();
        (new NotifyCampaignJob($user->id, $sub->id))
            ->handle($bot, app(GroupReportRenderer::class), app(PeriodParser::class));

        $bot->assertCalled('sendMessage', 1);
        $bot->assertRaw(fn ($request) => str_contains(self::messageText($request), '#8001')); // rendered with data

        $this->assertSame(2, $child->fresh()->resolved_position, 'detected funnel position cached');
    }

    public function test_invalid_position_422_during_probe_does_not_crash_digest(): void
    {
        // Regression: probing landing_uuids[N] beyond the funnel depth made AIO
        // return 422 and the exception killed the whole digest → nothing ever
        // delivered, last_notified never stamped, job retried forever.
        $this->steps = ['step-1' => ['lp-a', 'lp-b']];
        $this->trafficUuids = [];   // no traffic anywhere today
        $this->errorPosition = 3;   // landing_uuids[3] → 422, must be skipped
        [$user, $sub] = $this->subscribe();

        $bot = Nutgram::fake();
        (new NotifyCampaignJob($user->id, $sub->id))
            ->handle($bot, app(GroupReportRenderer::class), app(PeriodParser::class));

        // Digest still delivered (the silent one-liner) and stamped the child,
        // so the schedule won't re-fire-and-crash every tick.
        $bot->assertCalled('sendMessage', 1);
        $this->assertNotNull($sub->children()->first()->last_notified_at);
    }

    public function test_fully_silent_campaign_sends_short_one_liner(): void
    {
        $this->steps = ['step-1' => ['lp-a', 'lp-b']];
        $this->trafficUuids = []; // no traffic anywhere
        [$user, $sub] = $this->subscribe();

        $bot = Nutgram::fake();
        (new NotifyCampaignJob($user->id, $sub->id))
            ->handle($bot, app(GroupReportRenderer::class), app(PeriodParser::class));

        $bot->assertCalled('sendMessage', 1);
        $bot->assertRaw(function ($request) {
            $text = self::messageText($request);

            return str_contains($text, '😴')
                && str_contains($text, 'нет трафика')
                && ! str_contains($text, 'clicks'); // no dash table
        });

        $this->assertNotNull($sub->children()->first()->last_notified_at);
    }

    public function test_report_period_setting_drives_the_digest_window(): void
    {
        // A subscription configured to report "yesterday" must push a yesterday
        // window, not the old hardcoded "today" — the schedule (how often) and
        // the report window (which span) are independent knobs.
        $this->steps = ['step-1' => ['lp-a', 'lp-b']];
        $this->trafficUuids = ['lp-a', 'lp-b'];
        [$user, $sub] = $this->subscribe();

        $sub->report_period = 'yesterday';
        $sub->save();

        $bot = Nutgram::fake();
        (new NotifyCampaignJob($user->id, $sub->id))
            ->handle($bot, app(GroupReportRenderer::class), app(PeriodParser::class));

        $bot->assertCalled('sendMessage', 1);
        // The digest header carries the window label; "yesterday" here proves
        // the window came from report_period, not the default "today".
        $bot->assertRaw(fn ($request) => str_contains(self::messageText($request), 'yesterday'));
    }

    // ===== helpers =====

    /** Extract the `text` field from a captured Telegram API PSR request. */
    private static function messageText(\Psr\Http\Message\RequestInterface $request): string
    {
        $body = (string) $request->getBody();
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['text'])) {
            return (string) $json['text'];
        }
        // Multipart fallback — match on the raw body.
        return $body;
    }

    /** @return array{0: User, 1: CampaignSubscription} */
    private function subscribe(): array
    {
        $user = User::query()->create([
            'telegram_user_id' => 424692210,
            'telegram_username' => 'owner',
            'timezone' => 'Europe/Moscow',
        ]);
        app(CampaignSubscriptionService::class)->create($user, self::CAMPAIGN_UUID);
        $sub = CampaignSubscription::query()
            ->where('campaign_uuid', self::CAMPAIGN_UUID)->firstOrFail();

        return [$user, $sub];
    }

    /** @param  array<string, list<string>>  $steps */
    private function campaignEnvelope(array $steps): array
    {
        $settings = [];
        foreach ($steps as $stepUuid => $landingUuids) {
            $items = [];
            foreach ($landingUuids as $lu) {
                $items[] = ['payload' => [
                    'type' => 'Landing', 'uuid' => 'item-'.$lu, 'content' => $lu, 'isActive' => true,
                ]];
            }
            $settings[$stepUuid] = ['payload' => ['items' => $items]];
        }

        return [
            'fields' => [
                ['name' => 'name', 'value' => 'Digest Campaign'],
                ['name' => 'human_id', 'value' => 116400],
                ['name' => 'countries', 'value' => ['CA']],
                ['name' => 'settings', 'value' => json_encode($settings)],
            ],
            'data' => [], 'primary' => null, 'logs' => [],
        ];
    }

    private function landerEnvelope(string $uuid): array
    {
        // Stable per-uuid human_id so PrimitiveResolver can resolve tokens:
        // lp-a → 8001, lp-b → 8002 … (catalog backfill stores them).
        static $ids = [];
        if (! isset($ids[$uuid])) {
            $ids[$uuid] = 8000 + count($ids) + 1;
        }

        return [
            'fields' => [
                ['name' => 'name', 'value' => 'lander '.$uuid],
                ['name' => 'human_id', 'value' => $ids[$uuid]],
                ['name' => 'mvt_settings', 'value' => '[]'],
            ],
            'data' => [], 'primary' => null, 'logs' => [],
        ];
    }

    private function seedTargetMetrics(): void
    {
        foreach ([
            'clicks-uuid' => 'Q Visits',
            'leads-uuid' => 'Leads',
            'ftds-real-uuid' => 'Total FTDs',
            'real-cr-uuid' => 'Real Approve',
        ] as $uuid => $name) {
            \App\Models\Aio\Metric::query()->updateOrCreate(
                ['uuid' => $uuid],
                ['name' => $name, 'raw' => [], 'synced_at' => now()],
            );
        }
    }
}
