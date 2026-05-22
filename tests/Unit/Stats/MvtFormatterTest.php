<?php

namespace Tests\Unit\Stats;

use App\Models\Aio\Landing;
use App\Services\Stats\LandingFormatter;
use App\Services\Stats\MvtFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class MvtFormatterTest extends TestCase
{
    public function test_empty_rows_shows_no_variants_message(): void
    {
        $landing = $this->landing(33169);
        $html = $this->formatter()->format([
            'landing' => $landing,
            'window' => $this->window(),
            'rows' => [],
            'active_slugs' => [],
        ]);

        $this->assertStringContainsString('#33169', $html);
        $this->assertStringContainsString('нет активных MVT-вариантов', $html);
    }

    public function test_single_variant_renders_with_gold_medal(): void
    {
        $landing = $this->landing(33169);
        $html = $this->formatter()->format([
            'landing' => $landing,
            'window' => $this->window(),
            'rows' => [[
                'variants' => ['lp_header' => 'Headline A'],
                'metrics' => ['clicks' => 120, 'leads' => 12],
            ]],
            'active_slugs' => ['lp_header'],
        ]);

        $this->assertStringContainsString('🥇', $html);
        $this->assertStringContainsString('Headline A', $html);
        $this->assertStringContainsString('clicks', $html);
        $this->assertStringContainsString('120', $html);
        $this->assertStringContainsString('lp_header', $html);
    }

    public function test_two_variants_sorted_by_leads_with_delta_on_loser(): void
    {
        $landing = $this->landing(33169);
        $html = $this->formatter()->format([
            'landing' => $landing,
            'window' => $this->window(),
            'rows' => [
                ['variants' => ['lp_header' => 'A'], 'metrics' => ['clicks' => 100, 'leads' => 10]],
                ['variants' => ['lp_header' => 'B'], 'metrics' => ['clicks' => 100, 'leads' => 20]],  // leader
            ],
            'active_slugs' => ['lp_header'],
        ]);

        // B has more leads → comes first (🥇), A is silver, and A's metrics
        // get a delta column versus B.
        $bPos = strpos($html, 'B</code>');
        $aPos = strpos($html, 'A</code>');
        $this->assertLessThan($aPos, $bPos);
        $this->assertStringContainsString('🥇', $html);
        $this->assertStringContainsString('🥈', $html);
        $this->assertStringContainsString('-50.0%', $html);  // leads delta: (10-20)/20 = -50%
    }

    public function test_html_entities_in_variant_text_decoded_then_safely_escaped(): void
    {
        $landing = $this->landing(33169);
        $html = $this->formatter()->format([
            'landing' => $landing,
            'window' => $this->window(),
            'rows' => [[
                'variants' => ['lp_header' => '&quot;Quoted&quot; &mdash; clean'],
                'metrics' => ['clicks' => 1],
            ]],
            'active_slugs' => ['lp_header'],
        ]);

        // After decode-then-escape the user sees `&quot;Quoted&quot; — clean` in
        // raw HTML (browsers/Telegram render it as "Quoted" — clean).
        $this->assertStringContainsString('&quot;Quoted&quot;', $html);
        $this->assertStringContainsString('—', $html);
    }

    private function formatter(): MvtFormatter
    {
        return new MvtFormatter(new LandingFormatter);
    }

    private function landing(int $humanId): Landing
    {
        $l = new Landing;
        $l->uuid = 'lp-'.$humanId;
        $l->human_id = $humanId;
        $l->name = 'X';
        $l->landing_type_name = 'Celeb Preland';
        $l->countries = ['NG'];
        $l->is_archived = false;

        return $l;
    }

    private function window(): array
    {
        return [
            'from' => CarbonImmutable::create(2026, 5, 22, 0, 0, 0),
            'to' => CarbonImmutable::create(2026, 5, 22, 14, 30, 0),
            'timezone' => 'UTC',
            'label' => 'today',
        ];
    }
}
