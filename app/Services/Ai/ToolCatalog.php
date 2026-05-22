<?php

namespace App\Services\Ai;

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\PrimitiveResolver;
use App\Services\Stats\StatsFormatter;
use RuntimeException;

/**
 * Maps Claude tool calls onto existing services.
 *
 * Each tool returns a Telegram-HTML string (same format as the slash command)
 * so the LLM can echo it back verbatim or layer a short comment around it.
 *
 * Phase K shape: one tool — stats by primitive. The compare / list_aliases /
 * mvt_status tools were removed alongside the alias concept. Phase L/M/N
 * will reintroduce richer tools (compare across primitives, drilldowns).
 */
class ToolCatalog
{
    public function __construct(
        private readonly PrimitiveResolver $primitives,
        private readonly PeriodParser $periods,
        private readonly LandingReports $reports,
        private readonly TargetMetricSet $targets,
        private readonly StatsFormatter $formatter,
    ) {}

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'stats',
                'description' => 'Get totals for an AIO primitive (currently: 2-letter country code like DK, BR, IT, US). Call this whenever the user asks about a country slice of the data.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'primitive' => [
                            'type' => 'string',
                            'description' => 'Country code (ISO alpha-2, case-insensitive). E.g. DK, BR, IT, US, RU.',
                        ],
                        'period' => [
                            'type' => 'string',
                            'description' => 'Period: today (default), yesterday, week, month, last week, last month, or N{h,d,w,m}. Russian also works: сегодня, вчера, неделя, месяц, "3 дня", "7 часов".',
                        ],
                    ],
                    'required' => ['primitive'],
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
}
