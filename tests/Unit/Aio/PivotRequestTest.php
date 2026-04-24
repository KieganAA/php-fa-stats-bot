<?php

namespace Tests\Unit\Aio;

use App\Services\Aio\Pivot\PivotKeys;
use App\Services\Aio\Pivot\PivotRequest;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PivotRequestTest extends TestCase
{
    public function test_builds_flat_body_with_defaults(): void
    {
        $body = PivotRequest::create()
            ->dates('2026-04-01 00:00:00', '2026-04-07 23:59:59', 'Europe/Berlin')
            ->filter(PivotKeys::landingUuid(1), ['lp-1'])
            ->groupBy(PivotKeys::landingUuid(1))
            ->toArray();

        $this->assertSame(
            [
                'dates' => ['2026-04-01 00:00:00', '2026-04-07 23:59:59', 'Europe/Berlin'],
                'back_fix_attribution' => false,
                'event_time_attribution' => false,
                'hide_bots' => true,
                'hide_empty_metrics' => true,
                'hide_trash' => true,
                'conditions' => [['key' => 'landing_uuids[1]', 'values' => ['lp-1']]],
                'definitions' => [['key' => 'landing_uuids[1]']],
            ],
            $body,
        );
    }

    public function test_normalizes_datetime_objects(): void
    {
        $from = new DateTimeImmutable('2026-04-01 09:30:00');
        $to = new DateTimeImmutable('2026-04-01 12:30:00');

        $body = PivotRequest::create()
            ->dates($from, $to, 'Europe/Berlin')
            ->groupBy(PivotKeys::COUNTRY)
            ->toArray();

        $this->assertSame(['2026-04-01 09:30:00', '2026-04-01 12:30:00', 'Europe/Berlin'], $body['dates']);
    }

    public function test_multiple_conditions_and_definitions_preserve_order(): void
    {
        $body = PivotRequest::create()
            ->dates('2026-04-01 00:00:00', '2026-04-07 23:59:59')
            ->filter(PivotKeys::landingUuid(1), ['a', 'b'])
            ->filter(PivotKeys::CAMPAIGN, ['c'])
            ->groupByMany([PivotKeys::landingUuid(1), PivotKeys::COUNTRY])
            ->toArray();

        $this->assertSame(
            [
                ['key' => 'landing_uuids[1]', 'values' => ['a', 'b']],
                ['key' => 'campaign_uuid', 'values' => ['c']],
            ],
            $body['conditions'],
        );
        $this->assertSame(
            [['key' => 'landing_uuids[1]'], ['key' => 'location_country_code']],
            $body['definitions'],
        );
    }

    public function test_empty_filter_values_are_dropped(): void
    {
        $body = PivotRequest::create()
            ->dates('2026-04-01 00:00:00', '2026-04-07 23:59:59')
            ->filter(PivotKeys::CAMPAIGN, [])
            ->groupBy(PivotKeys::COUNTRY)
            ->toArray();

        $this->assertSame([], $body['conditions']);
    }

    public function test_toggle_setters(): void
    {
        $body = PivotRequest::create()
            ->dates('2026-04-01 00:00:00', '2026-04-07 23:59:59')
            ->hideBots(false)
            ->hideTrash(false)
            ->hideEmptyMetrics(false)
            ->backFixAttribution(true)
            ->eventTimeAttribution(true)
            ->groupBy(PivotKeys::COUNTRY)
            ->toArray();

        $this->assertFalse($body['hide_bots']);
        $this->assertFalse($body['hide_trash']);
        $this->assertFalse($body['hide_empty_metrics']);
        $this->assertTrue($body['back_fix_attribution']);
        $this->assertTrue($body['event_time_attribution']);
    }

    public function test_requires_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PivotRequest::create()
            ->groupBy(PivotKeys::COUNTRY)
            ->toArray();
    }

    public function test_requires_at_least_one_definition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PivotRequest::create()
            ->dates('2026-04-01 00:00:00', '2026-04-07 23:59:59')
            ->toArray();
    }

    public function test_rejects_more_than_seven_definitions(): void
    {
        $req = PivotRequest::create()
            ->dates('2026-04-01 00:00:00', '2026-04-07 23:59:59');

        for ($i = 0; $i < 8; $i++) {
            $req->groupBy("string_fields[f{$i}]");
        }

        $this->expectException(InvalidArgumentException::class);
        $req->toArray();
    }

    public function test_landing_uuid_key_is_positional(): void
    {
        $this->assertSame('landing_uuids[1]', PivotKeys::landingUuid(1));
        $this->assertSame('landing_uuids[2]', PivotKeys::landingUuid(2));
    }
}
