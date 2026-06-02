<?php

namespace Tests\Unit\Stats;

use App\Models\User;
use App\Services\Stats\MetricColumnResolver;
use Tests\TestCase;

/**
 * Per-context resolver coverage. The resolver reaches `config()` for the
 * stats-context default list, so we extend Tests\TestCase to boot the app
 * (gets us the config repo without touching the DB).
 */
class MetricColumnResolverTest extends TestCase
{
    public function test_falls_back_to_default_when_no_user(): void
    {
        $resolver = new MetricColumnResolver;

        $stats = $resolver->namesFor(null, MetricColumnResolver::STATS);
        $geo = $resolver->namesFor(null, MetricColumnResolver::GEO);

        $this->assertSame(MetricColumnResolver::defaultNames(MetricColumnResolver::STATS), $stats);
        $this->assertSame(['Q Visits', 'Leads', 'Real Approve'], $geo);
        $this->assertNotEmpty($stats);
    }

    public function test_per_context_pick_wins_over_defaults(): void
    {
        $user = $this->user([
            'metric_presets' => [
                'geo' => ['Q LP1 CTR', 'Leads'],
            ],
        ]);

        $resolver = new MetricColumnResolver;

        $this->assertSame(['Q LP1 CTR', 'Leads'], $resolver->namesFor($user, MetricColumnResolver::GEO));
        // Untouched contexts still see defaults.
        $this->assertSame(['Q Visits', 'Leads', 'Real Approve'], $resolver->namesFor($user, MetricColumnResolver::BUYERS));
    }

    public function test_legacy_metrics_key_seeds_stats_compare_tracking(): void
    {
        $user = $this->user(['metrics' => ['Total FTDs']]);

        $resolver = new MetricColumnResolver;

        $this->assertSame(['Total FTDs'], $resolver->namesFor($user, MetricColumnResolver::STATS));
        $this->assertSame(['Total FTDs'], $resolver->namesFor($user, MetricColumnResolver::COMPARE));
        $this->assertSame(['Total FTDs'], $resolver->namesFor($user, MetricColumnResolver::TRACKING));
        // Legacy key does NOT bleed into narrow ranking contexts.
        $this->assertSame(['Q Visits', 'Leads', 'Real Approve'], $resolver->namesFor($user, MetricColumnResolver::GEO));
    }

    public function test_explicit_preset_supersedes_legacy_key(): void
    {
        $user = $this->user([
            'metrics' => ['Total FTDs'],
            'metric_presets' => ['stats' => ['Q Visits', 'Leads']],
        ]);

        $names = (new MetricColumnResolver)->namesFor($user, MetricColumnResolver::STATS);

        $this->assertSame(['Q Visits', 'Leads'], $names);
    }

    public function test_columns_use_label_overrides_and_kinds(): void
    {
        $user = $this->user([
            'metric_presets' => ['stats' => ['Q Visits', 'Real Approve']],
            'metric_labels' => ['Q Visits' => 'Quals'],
        ]);

        $cols = (new MetricColumnResolver)->columnsFor($user, MetricColumnResolver::STATS);

        $this->assertCount(2, $cols);
        $this->assertSame(['name' => 'Q Visits', 'label' => 'Quals', 'kind' => 'count'], $cols[0]);
        // Untouched override → built-in label ("CR%") + ratio kind.
        $this->assertSame('Real Approve', $cols[1]['name']);
        $this->assertSame('CR%', $cols[1]['label']);
        $this->assertSame('ratio', $cols[1]['kind']);
    }

    public function test_blank_or_non_string_entries_filtered(): void
    {
        $user = $this->user([
            'metric_presets' => ['stats' => ['  Q Visits  ', '', '   ', 42]],
        ]);

        $names = (new MetricColumnResolver)->namesFor($user, MetricColumnResolver::STATS);

        $this->assertSame(['Q Visits'], $names);
    }

    public function test_unknown_context_defaults_to_stats(): void
    {
        $user = $this->user(['metric_presets' => ['stats' => ['Leads']]]);

        $names = (new MetricColumnResolver)->namesFor($user, 'bogus');

        $this->assertSame(['Leads'], $names);
    }

    public function test_has_custom_preset_distinguishes_explicit_from_default(): void
    {
        $resolver = new MetricColumnResolver;
        $empty = $this->user([]);
        $picked = $this->user(['metric_presets' => ['geo' => ['Leads']]]);

        $this->assertFalse($resolver->hasCustomPreset($empty, MetricColumnResolver::GEO));
        $this->assertTrue($resolver->hasCustomPreset($picked, MetricColumnResolver::GEO));
        $this->assertFalse($resolver->hasCustomPreset($picked, MetricColumnResolver::BUYERS));
    }

    public function test_label_overrides_filtered_and_trimmed(): void
    {
        $user = $this->user([
            'metric_labels' => [
                'Q Visits' => '  Quals  ',
                'Leads' => '',           // blank → dropped
                '' => 'X',                // blank name → dropped
                'Foo' => null,            // null → dropped (not string)
            ],
        ]);

        $out = (new MetricColumnResolver)->labelsFor($user);

        $this->assertSame(['Q Visits' => 'Quals'], $out);
    }

    public function test_columns_from_names_pure_helper(): void
    {
        $cols = MetricColumnResolver::columnsFromNames(
            ['Q Visits', 'Total FTDs'],
            ['Q Visits' => 'Quals'],
        );

        $this->assertCount(2, $cols);
        $this->assertSame('Quals', $cols[0]['label']);
        $this->assertSame('count', $cols[0]['kind']);
        $this->assertSame('FTDs', $cols[1]['label']); // built-in
    }

    /** @param  array<string, mixed>  $settings */
    private function user(array $settings): User
    {
        $u = new User;
        $u->settings = $settings;

        return $u;
    }
}
