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

    public function test_day_before_yesterday(): void
    {
        $parser = new PeriodParser('UTC');
        $r = $parser->parse('позавчера');

        $this->assertSame('day before yesterday', $r['label']);
        $this->assertSame('2026-04-23 00:00:00', $r['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-23 23:59:59', $r['to']->format('Y-m-d H:i:s'));
    }

    public function test_russian_week_aliases(): void
    {
        $parser = new PeriodParser('UTC');

        $this->assertSame('this week', $parser->parse('неделя')['label']);
        $this->assertSame('this week', $parser->parse('неделю')['label']);
        $this->assertSame('this week', $parser->parse('за неделю')['label']);
    }

    public function test_za_prefix_unwraps(): void
    {
        $parser = new PeriodParser('UTC');

        $this->assertSame('yesterday', $parser->parse('за вчера')['label']);
        $this->assertSame('this month', $parser->parse('за месяц')['label']);
        $this->assertSame('7d', $parser->parse('за 7d')['label']);
    }

    public function test_russian_n_days_shorthand(): void
    {
        $parser = new PeriodParser('UTC');

        $r = $parser->parse('3 дня');
        $this->assertSame('3d', $r['label']);
        $this->assertSame('2026-04-22 14:30:00', $r['from']->format('Y-m-d H:i:s'));

        $this->assertSame('5d', $parser->parse('5 дней')['label']);
        $this->assertSame('1d', $parser->parse('1 д')['label']);
    }

    public function test_russian_n_hours_shorthand(): void
    {
        $parser = new PeriodParser('UTC');

        $this->assertSame('2h', $parser->parse('2 ч')['label']);
        $this->assertSame('3h', $parser->parse('3 часа')['label']);
        $this->assertSame('5h', $parser->parse('5 часов')['label']);
    }

    public function test_russian_n_weeks_shorthand(): void
    {
        $this->assertSame('2w', (new PeriodParser('UTC'))->parse('2 недели')['label']);
    }

    public function test_now_and_chas_map_to_1h(): void
    {
        $parser = new PeriodParser('UTC');

        $this->assertSame('1h', $parser->parse('сейчас')['label']);
        $this->assertSame('1h', $parser->parse('час')['label']);
        $this->assertSame('1h', $parser->parse('now')['label']);
    }

    public function test_sutki_is_24h(): void
    {
        $this->assertSame('24h', (new PeriodParser('UTC'))->parse('сутки')['label']);
    }

    public function test_last_month(): void
    {
        $parser = new PeriodParser('UTC');
        $r = $parser->parse('прошлый месяц');

        $this->assertSame('last month', $r['label']);
        $this->assertSame('2026-03-01 00:00:00', $r['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 23:59:59', $r['to']->format('Y-m-d H:i:s'));
    }
}
