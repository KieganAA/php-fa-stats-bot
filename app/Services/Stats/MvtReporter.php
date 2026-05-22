<?php

namespace App\Services\Stats;

use App\Models\Aio\Field;
use App\Models\Aio\Landing;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Aio\Pivot\VariantValueDecoder;
use RuntimeException;

/**
 * MVT breakdown for a single landing — "which creative variant is winning?"
 *
 * Phase O.4 strategy: rather than ask the user which lp_* fields to group by
 * (ugly UX) or hammer AIO once per field (expensive), we send ONE pivot
 * request grouped by a curated 5-field set of the most common MVT slots:
 *
 *   lp_header, lp_content_var_subheading, lp_content_var_header_image,
 *   lp_mvt_content_var, lp_content_var_instruction
 *
 * AIO returns one row per (variant tuple). We discard rows where every
 * variant is empty (no MVT for this landing — show a fallback message) and
 * trim the displayed dimensions to fields that actually have variation.
 *
 * Result row shape (after `report()`):
 *   [
 *     'landing'     => Landing,
 *     'window'      => PeriodParser window,
 *     'rows'        => list<array{variants: array<string,string>, metrics: array<string,int|float|null>}>,
 *     'active_slugs'=> list<string>   // fields where AT LEAST one variant value is non-empty
 *   ]
 */
final class MvtReporter
{
    /** Order matters for column layout in the final report. */
    private const PREFERRED_FIELDS = [
        'lp_header',
        'lp_content_var_subheading',
        'lp_content_var_header_image',
        'lp_mvt_content_var',
        'lp_content_var_instruction',
    ];

    public function __construct(
        private readonly LandingReports $reports,
        private readonly TargetMetricSet $targets,
    ) {}

    /**
     * @param  array{from: \DateTimeInterface, to: \DateTimeInterface, timezone: string, label: string}  $window
     */
    public function report(Landing $landing, array $window, int $position = 1): array
    {
        $fields = $this->loadFields();
        if ($fields === []) {
            throw new RuntimeException(
                'Нет lp_* полей в aio_fields. Запусти artisan aio:sync:fields.'
            );
        }

        $response = $this->reports->mvtBreakdown(
            landingUuid: $landing->uuid,
            position: $position,
            mvtFields: array_values($fields),
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $slugByGroup = [];
        foreach (array_values($fields) as $i => $f) {
            $slugByGroup["group_{$i}"] = $f->slug;
        }

        $rows = [];
        $activeSlugs = [];
        foreach ($response->rows as $raw) {
            $variants = [];
            $anyNonEmpty = false;
            foreach ($raw['dimensions'] as $groupKey => $value) {
                $slug = $slugByGroup[$groupKey] ?? $groupKey;
                $decoded = VariantValueDecoder::decode((string) $value);
                $variants[$slug] = $decoded;
                if ($decoded !== '') {
                    $anyNonEmpty = true;
                    $activeSlugs[$slug] = true;
                }
            }
            if (! $anyNonEmpty) {
                continue;
            }
            $rows[] = [
                'variants' => $variants,
                'metrics' => $this->targets->project($raw['metrics']),
            ];
        }

        return [
            'landing' => $landing,
            'window' => $window,
            'rows' => $rows,
            'active_slugs' => array_keys($activeSlugs),
        ];
    }

    /**
     * Loads the configured MVT fields in PREFERRED_FIELDS order, dropping
     * any that aren't synced yet. Capped to 7 — AIO rejects more definitions.
     *
     * @return array<string, Field>
     */
    private function loadFields(): array
    {
        $all = Field::query()
            ->whereIn('slug', self::PREFERRED_FIELDS)
            ->get()
            ->keyBy('slug');

        $ordered = [];
        foreach (self::PREFERRED_FIELDS as $slug) {
            if ($all->has($slug)) {
                $ordered[$slug] = $all->get($slug);
            }
            if (count($ordered) >= 7) {
                break;
            }
        }

        return $ordered;
    }
}
