<?php

namespace App\Services\Aio;

use App\Services\Aio\Dto\Field;
use App\Services\Aio\Dto\Landing;
use App\Services\Aio\Dto\LandingType;
use App\Services\Aio\Dto\Metric;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Dto\User;
use App\Services\Aio\Http\AioHttpClient;
use App\Services\Aio\Http\Paginator;
use Carbon\CarbonImmutable;
use Generator;

class AioClient
{
    /**
     * Defaults required by AIO's POST /api/v1/tables/data body — server rejects
     * or returns empty without them. Keep the shape exactly as the web app sends.
     */
    private const TABLE_REQUEST_DEFAULTS = [
        'search' => '',
        'sort_key' => '',
        'sort_direction' => 'asc',
        'analytics_position' => 1,
        'filters' => [],
        'dates' => [],
        'hide_empty_metrics' => false,
        'hide_bots' => true,
        'unwrap_tree' => false,
        'hide_trash' => true,
        'event_time_attribution' => false,
        'back_fix_attribution' => false,
        'metric_filters' => [],
        'metric_definition' => null,
        'metric_definitions' => [],
    ];

    public function __construct(
        private readonly AioHttpClient $http,
    ) {}

    /** @return Generator<int, Landing> */
    public function streamLandings(int $limit = 100): Generator
    {
        foreach ($this->streamList('api/v1/data/landings', $limit) as $row) {
            yield Landing::fromArray($row);
        }
    }

    /** @return Generator<int, LandingType> */
    public function streamLandingTypes(int $limit = 100): Generator
    {
        foreach ($this->streamList('api/v1/data/landing-types', $limit) as $row) {
            yield LandingType::fromArray($row);
        }
    }

    /** @return Generator<int, User> */
    public function streamUsers(int $limit = 100): Generator
    {
        foreach ($this->streamList('api/v1/data/users', $limit) as $row) {
            yield User::fromArray($row);
        }
    }

    /** @return Generator<int, Field> */
    public function streamFields(int $limit = 100): Generator
    {
        foreach ($this->streamTable('Settings\\Fields', $limit) as $row) {
            yield Field::fromArray($row);
        }
    }

    /** @return Generator<int, Metric> */
    public function streamMetrics(int $limit = 100): Generator
    {
        foreach ($this->streamTable('Settings\\Metrics', $limit) as $row) {
            yield Metric::fromArray($row);
        }
    }

    /** @return array<int, Landing> */
    public function listLandings(int $limit = 100): array
    {
        return iterator_to_array($this->streamLandings($limit), false);
    }

    /** @return array<int, LandingType> */
    public function listLandingTypes(int $limit = 100): array
    {
        return iterator_to_array($this->streamLandingTypes($limit), false);
    }

    /** @return array<int, User> */
    public function listUsers(int $limit = 100): array
    {
        return iterator_to_array($this->streamUsers($limit), false);
    }

    /** @return array<int, Field> */
    public function listFields(int $limit = 100): array
    {
        return iterator_to_array($this->streamFields($limit), false);
    }

    /** @return array<int, Metric> */
    public function listMetrics(int $limit = 100): array
    {
        return iterator_to_array($this->streamMetrics($limit), false);
    }

    public function runLanderCreateAction(array $uuids): array
    {
        return $this->http->post('api/v1/actions/data', [
            'uuids' => $uuids,
            'action' => 'Lander\\Create',
            'repository' => 'Eloquent\\LanderRepository',
        ], cacheTtl: 0);
    }

    /**
     * The same action endpoint, but for campaigns. Response shape mirrors the
     * lander one: `{ fields: [{name, value}, ...], data, primary, logs }`.
     * The interesting payload is `fields[name=="settings"].value` — a JSON
     * string that decodes to a dict of step_uuid → {payload: {items: […]}}.
     * See CampaignStructureFetcher for the parsing logic.
     */
    public function runCampaignCreateAction(array $uuids, ?int $cacheTtl = null): array
    {
        return $this->http->post('api/v1/actions/data', [
            'uuids' => $uuids,
            'action' => 'Campaign\\Create',
            'repository' => 'Eloquent\\CampaignRepository',
        ], cacheTtl: $cacheTtl);
    }

    /**
     * Search the campaigns table (POST /api/v1/tables/data, table
     * `Tracker\Campaigns`) — used to turn a human_id into the campaign uuid the
     * structure fetcher needs. Returns the raw `rows`; each carries an
     * `_identity` block with `uuid`, `human_id` (zero-padded string) and `name`.
     *
     * `hide_empty_metrics` stays false so campaigns with no visits in the window
     * still surface — we're looking one up by id, not ranking by traffic. The
     * campaign-uuid metric_definition is what makes the table key on campaigns.
     *
     * @return list<array<string, mixed>>
     */
    public function searchCampaigns(string $query, int $limit = 50): array
    {
        $now = CarbonImmutable::now();

        $response = $this->http->post('api/v1/tables/data', [
            'request' => [
                'Tracker\\Campaigns' => array_merge(
                    ['table' => 'Tracker\\Campaigns', 'page' => 1, 'limit' => $this->capLimit($limit)],
                    self::TABLE_REQUEST_DEFAULTS,
                    [
                        'search' => $query,
                        'sort_key' => 'owner',
                        'sort_direction' => 'desc',
                        // Current calendar month. AIO 500s on windows that cross
                        // a month boundary, and with hide_empty_metrics=false a
                        // campaign surfaces regardless of activity in the window,
                        // so a single-month range is both safe and sufficient.
                        'dates' => [
                            $now->startOfMonth()->format('Y-m-d 00:00:00'),
                            $now->endOfMonth()->format('Y-m-d 23:59:59'),
                            'UTC',
                        ],
                        'metric_definition' => [
                            'clickhouse_key' => 'campaign_uuid',
                            'key' => 'campaign_uuid',
                            'model_key' => 'uuid',
                            'clickhouse_model_key' => 'column',
                            'type' => 'column',
                            'main_for' => 'Visits Based',
                        ],
                    ],
                ),
            ],
        ], cacheTtl: 0);

        $rows = $response['Tracker\\Campaigns']['response']['rows'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * POST /api/v1/pivot-report/data — body is flat per AIO docs:
     * {dates, back_fix_attribution, event_time_attribution, hide_bots,
     *  hide_empty_metrics, hide_trash, conditions[], definitions[]}.
     * Pass $heavy=true for wide/long-window reports.
     */
    public function pivotReport(array $body, bool $heavy = true, ?int $cacheTtl = null): PivotResponse
    {
        $response = $this->http->post(
            'api/v1/pivot-report/data',
            $body,
            cacheTtl: $cacheTtl,
            heavy: $heavy,
        );

        return PivotResponse::fromArray($response);
    }

    /**
     * POST /api/v1/tables/data — walks pages of `rows` from the wrapped response.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function streamTable(string $table, int $limit, array $extraRequest = []): Generator
    {
        $capped = $this->capLimit($limit);

        yield from Paginator::iterate(
            fetcher: function (int $page, int $limitArg) use ($table, $extraRequest) {
                $response = $this->http->post('api/v1/tables/data', [
                    'request' => [
                        $table => array_merge(
                            ['table' => $table, 'page' => $page, 'limit' => $limitArg],
                            self::TABLE_REQUEST_DEFAULTS,
                            $extraRequest,
                        ),
                    ],
                ]);

                $inner = $response[$table]['response'] ?? [];

                return [
                    'rows' => $inner['rows'] ?? [],
                    'next' => (bool) ($inner['next'] ?? false),
                ];
            },
            limit: $capped,
        );
    }

    /** @return Generator<int, array<string, mixed>> */
    private function streamList(string $path, int $limit): Generator
    {
        $capped = $this->capLimit($limit);

        yield from Paginator::iterate(
            fn (int $page) => $this->http->get($path, ['page' => $page, 'limit' => $capped]),
            limit: $capped,
        );
    }

    private function capLimit(int $limit): int
    {
        return max(1, min($limit, 100));
    }
}
