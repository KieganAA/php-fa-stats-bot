<?php

namespace App\Services\Ai;

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Stats\ComparisonReporter;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\PrimitiveResolver;
use App\Services\Stats\StatsFormatter;
use RuntimeException;

/**
 * Maps Claude tool calls onto existing services.
 *
 * Each tool returns a Telegram-HTML string (same format as the slash command)
 * so the LLM can echo it back verbatim or layer a short comment around it.
 */
class ToolCatalog
{
    public function __construct(
        private readonly PrimitiveResolver $primitives,
        private readonly PeriodParser $periods,
        private readonly LandingReports $reports,
        private readonly TargetMetricSet $targets,
        private readonly StatsFormatter $formatter,
        private readonly ComparisonReporter $comparisons,
    ) {}

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'stats',
                'description' => 'Get totals for one AIO slice. Use for a country, a specific landing (numeric ID), or a UUID.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'primitive' => [
                            'type' => 'string',
                            'description' => 'One of: 2-letter country code (DK, BR, IT, US), numeric landing human_id (33169, 205228), or full landing UUID.',
                        ],
                        'period' => [
                            'type' => 'string',
                            'description' => 'Period: today (default), yesterday, week, month, last week, last month, or N{h,d,w,m}. Russian works too: сегодня, вчера, неделя, месяц, "3 дня", "7 часов".',
                        ],
                    ],
                    'required' => ['primitive'],
                ],
            ],
            [
                'name' => 'compare',
                'description' => 'Compare 2+ AIO slices side-by-side with Δ%. All primitives must be the same kind (only landings, or only countries). For two-slice queries the response includes a delta column vs the first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'primitives' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of 2+ primitives, all of the same kind. Example: ["33169","205215"] or ["DK","BR"].',
                            'minItems' => 2,
                        ],
                        'period' => [
                            'type' => 'string',
                            'description' => 'Same syntax as stats.period.',
                        ],
                    ],
                    'required' => ['primitives'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function dispatch(string $name, array $input): string
    {
        return match ($name) {
            'stats' => $this->stats(
                (string) ($input['primitive'] ?? ''),
                $input['period'] ?? null,
            ),
            'compare' => $this->compare(
                (array) ($input['primitives'] ?? []),
                $input['period'] ?? null,
            ),
            default => throw new RuntimeException("Unknown tool: {$name}"),
        };
    }

    private function stats(string $primitive, ?string $period): string
    {
        $resolved = $this->primitives->resolve($primitive);
        $window = $this->periods->parse(is_string($period) ? $period : null);

        $pivot = $this->reports->statsByPrimitive(
            filterKey: $resolved['filter_key'],
            filterValue: $resolved['filter_value'],
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $raw = $pivot->rows[0]['metrics'] ?? [];
        $projected = $this->targets->project($raw);

        return $this->formatter->format($window, [
            ['label' => $resolved['label'], 'metrics' => $projected],
        ]);
    }

    /** @param  array<int|string, mixed>  $primitives */
    private function compare(array $primitives, ?string $period): string
    {
        $tokens = array_values(array_filter(
            array_map(fn ($p) => is_string($p) ? trim($p) : '', $primitives),
            fn ($t) => $t !== '',
        ));
        if (count($tokens) < 2) {
            throw new RuntimeException('compare нуждается минимум в 2 примитивах.');
        }

        $window = $this->periods->parse(is_string($period) ? $period : null);

        return $this->comparisons->report($tokens, $window);
    }
}
