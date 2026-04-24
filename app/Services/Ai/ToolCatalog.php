<?php

namespace App\Services\Ai;

use App\Models\TrackedLanding;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Stats\AliasResolver;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\StatsFormatter;
use RuntimeException;

/**
 * Maps Claude tool calls to existing services.
 *
 * Each tool returns a Telegram-HTML string (same format as the slash commands)
 * so the LLM can echo it back verbatim, summarize, or chain into another call.
 */
class ToolCatalog
{
    public function __construct(
        private readonly AliasResolver $aliases,
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
                'description' => 'Get landing-page metrics for a single landing. Use when the user asks about one landing.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'alias' => ['type' => 'string', 'description' => 'Alias name, numeric human_id, or UUID of the landing.'],
                        'period' => ['type' => 'string', 'description' => 'Period token: today (default), yesterday, week, month, last week, this month, or N{h,d,w,m} like 24h or 7d.'],
                    ],
                    'required' => ['alias'],
                ],
            ],
            [
                'name' => 'compare',
                'description' => 'Compare metrics across multiple landings on the same position. Use when the user asks to compare or rank.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'aliases' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Aliases / human_ids / UUIDs. Need at least 2.',
                            'minItems' => 2,
                        ],
                        'period' => ['type' => 'string', 'description' => 'Period token, same syntax as stats.'],
                    ],
                    'required' => ['aliases'],
                ],
            ],
            [
                'name' => 'list_aliases',
                'description' => 'List all configured landing aliases with their landing names and positions. Use when the user asks what aliases exist.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass,
                ],
            ],
            [
                'name' => 'mvt_status',
                'description' => 'List landings currently being tracked for MVT 3h slices, with their position and tracked fields.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass,
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
            'stats' => $this->stats((string) ($input['alias'] ?? ''), $input['period'] ?? null),
            'compare' => $this->compare((array) ($input['aliases'] ?? []), $input['period'] ?? null),
            'list_aliases' => $this->listAliases(),
            'mvt_status' => $this->mvtStatus(),
            default => throw new RuntimeException("Unknown tool: {$name}"),
        };
    }

    private function stats(string $alias, ?string $period): string
    {
        $resolved = $this->aliases->resolve($alias);
        $window = $this->periods->parse(is_string($period) ? $period : null);

        $position = $resolved['alias']?->position ?? 1;
        $pivot = $this->reports->landingStats(
            landingUuid: $resolved['landing']->uuid,
            position: $position,
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $metrics = $pivot->rows[0]['metrics'] ?? [];
        $projected = $this->targets->project($metrics);

        $label = $this->label($resolved['alias']?->alias, $resolved['landing']->name, $position);

        return $this->formatter->format($window, [
            ['label' => $label, 'metrics' => $projected],
        ]);
    }

    /** @param  array<int|string, mixed>  $aliases */
    private function compare(array $aliases, ?string $period): string
    {
        $tokens = array_values(array_filter(array_map(fn ($a) => is_string($a) ? $a : '', $aliases), fn ($t) => $t !== ''));
        if (count($tokens) < 2) {
            throw new RuntimeException('compare needs at least 2 aliases.');
        }

        $resolved = $this->aliases->resolveAll($tokens);

        $positions = array_unique(array_map(fn ($r) => $r['alias']?->position ?? 1, $resolved));
        if (count($positions) > 1) {
            throw new RuntimeException('All aliases must be on the same position (LP1, LP2, …).');
        }
        $position = (int) array_values($positions)[0];

        $window = $this->periods->parse(is_string($period) ? $period : null);
        $uuids = array_map(fn ($r) => $r['landing']->uuid, $resolved);

        $pivot = $this->reports->compareLandings(
            landingUuids: array_values($uuids),
            position: $position,
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $byUuid = [];
        foreach ($pivot->rows as $row) {
            $uuid = (string) ($row['dimensions']['group_0'] ?? '');
            $byUuid[$uuid] = $row['metrics'];
        }

        $entries = [];
        foreach ($resolved as $r) {
            $raw = $byUuid[$r['landing']->uuid] ?? [];
            $entries[] = [
                'label' => $this->label($r['alias']?->alias, $r['landing']->name, $position),
                'metrics' => $this->targets->project($raw),
            ];
        }

        return $this->formatter->format($window, $entries);
    }

    private function listAliases(): string
    {
        $aliases = $this->aliases->listAll();
        if ($aliases->isEmpty()) {
            return '<i>Алиасов нет.</i>';
        }

        $lines = ['<b>Алиасы:</b>'];
        foreach ($aliases as $a) {
            $name = htmlspecialchars((string) ($a->landing?->name ?: $a->landing_uuid), ENT_QUOTES);
            $lines[] = "• <code>{$a->alias}</code> → {$name} (LP{$a->position})";
        }

        return implode("\n", $lines);
    }

    private function mvtStatus(): string
    {
        $tracked = TrackedLanding::query()
            ->with(['landing', 'mvtFields'])
            ->whereNull('paused_at')
            ->orderBy('id')
            ->get();

        if ($tracked->isEmpty()) {
            return '<i>Нет лендингов в трекинге.</i>';
        }

        $lines = ['<b>В трекинге:</b>'];
        foreach ($tracked as $t) {
            $name = htmlspecialchars((string) ($t->landing?->name ?: $t->landing_uuid), ENT_QUOTES);
            $fieldCount = $t->mvtFields->count();
            $lines[] = "• {$name} (LP{$t->position}) — {$fieldCount} полей";
        }

        return implode("\n", $lines);
    }

    private function label(?string $alias, string $name, int $position): string
    {
        if ($alias) {
            return "{$alias} (LP{$position})";
        }

        return $name." [LP{$position}]";
    }
}
