<?php

namespace Tests\Feature\Campaign;

use App\Services\Campaign\CampaignDirectory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * human_id → uuid lookup against the `Tracker\Campaigns` table. The table
 * reports human_id as a zero-padded string ("036469") while users type it
 * either way; search may also return substring near-matches we must reject.
 */
final class CampaignDirectoryTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

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

        Http::fake([
            'app.aio.test/api/v1/tables/data*' => fn () => Http::response([
                'Tracker\\Campaigns' => ['response' => ['rows' => $this->rows, 'next' => false]],
            ]),
        ]);
    }

    public function test_resolves_padded_human_id_to_uuid(): void
    {
        $this->rows = [$this->row('aaaaaaaa-0000-0000-0000-000000000001', '036469', 'Catch')];

        // User types the padded form.
        $found = $this->directory()->findByHumanId('036469');

        $this->assertNotNull($found);
        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000001', $found['uuid']);
        $this->assertSame(36469, $found['human_id']);
        $this->assertSame('Catch', $found['name']);
    }

    public function test_resolves_unpadded_human_id(): void
    {
        $this->rows = [$this->row('aaaaaaaa-0000-0000-0000-000000000001', '036469', 'Catch')];

        // User drops the leading zeros — still matches numerically.
        $found = $this->directory()->findByHumanId('36469');

        $this->assertNotNull($found);
        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000001', $found['uuid']);
    }

    public function test_rejects_substring_near_match_picks_exact(): void
    {
        // Search for 6469 may also return 036469 (substring); only the exact
        // numeric match should win.
        $this->rows = [
            $this->row('aaaaaaaa-0000-0000-0000-000000000001', '036469', 'Padded'),
            $this->row('bbbbbbbb-0000-0000-0000-000000000002', '006469', 'Exact'),
        ];

        $found = $this->directory()->findByHumanId('6469');

        $this->assertNotNull($found);
        $this->assertSame('bbbbbbbb-0000-0000-0000-000000000002', $found['uuid']);
    }

    public function test_returns_null_when_no_exact_match(): void
    {
        $this->rows = [$this->row('aaaaaaaa-0000-0000-0000-000000000001', '036469', 'Catch')];

        $this->assertNull($this->directory()->findByHumanId('999999'));
    }

    public function test_returns_null_for_empty_or_zero(): void
    {
        $this->rows = [$this->row('aaaaaaaa-0000-0000-0000-000000000001', '036469', 'Catch')];

        $this->assertNull($this->directory()->findByHumanId('000000'));
        $this->assertNull($this->directory()->findByHumanId(''));
    }

    private function directory(): CampaignDirectory
    {
        return app(CampaignDirectory::class);
    }

    /** @return array<string, mixed> */
    private function row(string $uuid, string $humanId, string $name): array
    {
        return [
            'uuid' => $uuid,
            '_identity' => ['uuid' => $uuid, 'human_id' => $humanId, 'name' => $name],
        ];
    }
}
