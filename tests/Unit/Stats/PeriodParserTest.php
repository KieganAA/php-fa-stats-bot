<?php

namespace Tests\Unit\Stats;

use App\Services\Stats\PeriodParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PeriodParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-04-25 14:30:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_default_token_is_today(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse(null);

        $this->assertSame('today', $result['label']);
        $this->assertSame('UTC', $result['timezone']);
        $this->assertSame('2026-04-25 00:00:00', $result['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-25 14:30:00', $result['to']->format('Y-m-d H:i:s'));
    }

    public function test_empty_string_treated_as_today(): void
    {
        $parser = new PeriodParser('UTC');

        $this->assertSame('today', $parser->parse('')['label']);
    }

    public function test_today_token(): void
    {
        $parser = new PeriodParser('UTC');

        $this->assertSame('today', $parser->parse('today')['label']);
    }

    public function test_russian_today_alias(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('сегодня');
        $this->assertSame('today', $result['label']);
    }

    public function test_yesterday(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('yesterday');

        $this->assertSame('yesterday', $result['label']);
        $this->assertSame('2026-04-24 00:00:00', $result['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-24 23:59:59', $result['to']->format('Y-m-d H:i:s'));
    }

    public function test_russian_yesterday_alias(): void
    {
        $parser = new PeriodParser('UTC');

        $this->assertSame('yesterday', $parser->parse('вчера')['label']);
    }

    public function test_this_week(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('week');

        $this->assertSame('this week', $result['label']);
        $this->assertSame('2026-04-20 00:00:00', $result['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-25 14:30:00', $result['to']->format('Y-m-d H:i:s'));
    }

    public function test_last_week(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('last week');

        $this->assertSame('last week', $result['label']);
        $this->assertSame('2026-04-13 00:00:00', $result['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-19 23:59:59', $result['to']->format('Y-m-d H:i:s'));
    }

    public function test_this_month(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('month');

        $this->assertSame('this month', $result['label']);
        $this->assertSame('2026-04-01 00:00:00', $result['from']->format('Y-m-d H:i:s'));
    }

    public function test_n_hours(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('24h');

        $this->assertSame('24h', $result['label']);
        $this->assertSame('2026-04-24 14:30:00', $result['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-25 14:30:00', $result['to']->format('Y-m-d H:i:s'));
    }

    public function test_n_days(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('7d');

        $this->assertSame('7d', $result['label']);
        $this->assertSame('2026-04-18 14:30:00', $result['from']->format('Y-m-d H:i:s'));
    }

    public function test_n_weeks(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('2w');

        $this->assertSame('2w', $result['label']);
        $this->assertSame('2026-04-11 14:30:00', $result['from']->format('Y-m-d H:i:s'));
    }

    public function test_n_months(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('1m');

        $this->assertSame('1m', $result['label']);
        $this->assertSame('2026-03-25 14:30:00', $result['from']->format('Y-m-d H:i:s'));
    }

    public function test_unknown_token_throws(): void
    {
        $parser = new PeriodParser('UTC');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/Unknown period/");
        $parser->parse('next-tuesday');
    }

    public function test_timezone_argument_overrides_default(): void
    {
        $parser = new PeriodParser('UTC');

        $result = $parser->parse('today', 'Europe/Berlin');

        $this->assertSame('Europe/Berlin', $result['timezone']);
        $this->assertSame('Europe/Berlin', $result['from']->getTimezone()->getName());
    }

    public function test_case_insensitive_tokens(): void
    {
        $parser = new PeriodParser('UTC');

        $this->assertSame('today', $parser->parse('TODAY')['label']);
        $this->assertSame('yesterday', $parser->parse('Yesterday')['label']);
        $this->assertSame('7d', $parser->parse('7D')['label']);
    }
}
