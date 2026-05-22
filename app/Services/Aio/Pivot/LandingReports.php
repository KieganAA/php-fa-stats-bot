<?php

namespace App\Services\Aio\Pivot;

use App\Models\Aio\Field;
use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\PivotResponse;
use DateTimeInterface;

/**
 * Task-shaped pivot queries around landings:
 *   - landingStats: one landing's metrics over a window
 *   - compareLandings: side-by-side metrics for a list of landings
 *   - mvtBreakdown: one landing, split by its MVT custom-field variations
 *
 * Thin wrappers over PivotRequest — keep request-building knowledge here so
 * the bot command / AI tool layer stays narrative.
 */
class LandingReports
{
    public function __construct(private readonly AioClient $aio) {}

    public function landingStats(
        string $landingUuid,
        int $position,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $timezone = 'UTC',
        bool $heavy = false,
    ): PivotResponse {
        $body = PivotRequest::create()
            ->dates($from, $to, $timezone)
            ->filter(PivotKeys::landingUuid($position), [$landingUuid])
            ->groupBy(PivotKeys::landingUuid($position))
            ->toArray();

        return $this->aio->pivotReport($body, heavy: $heavy);
    }

    /**
     * Stats filtered by ONE primitive dimension (country / source / campaign /
     * buyer / landing). Groups by the same key the filter is applied to —
     * the response then has exactly one row of totals.
     */
    public function statsByPrimitive(
        string $filterKey,
        string $filterValue,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $timezone = 'UTC',
        bool $heavy = false,
    ): PivotResponse {
        $body = PivotRequest::create()
            ->dates($from, $to, $timezone)
            ->filter($filterKey, [$filterValue])
            ->groupBy($filterKey)
            ->toArray();

        return $this->aio->pivotReport($body, heavy: $heavy);
    }

    /**
     * Compare N primitives that share the same AIO dimension key (countries,
     * landings at the same position, …). One AIO call, grouped by the key,
     * returning one row per value found.
     *
     * @param  list<string>  $filterValues
     */
    public function compareByPrimitive(
        string $filterKey,
        array $filterValues,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $timezone = 'UTC',
        bool $heavy = false,
    ): PivotResponse {
        $body = PivotRequest::create()
            ->dates($from, $to, $timezone)
            ->filter($filterKey, $filterValues)
            ->groupBy($filterKey)
            ->toArray();

        return $this->aio->pivotReport($body, heavy: $heavy);
    }

    /** @param  list<string>  $landingUuids */
    public function compareLandings(
        array $landingUuids,
        int $position,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $timezone = 'UTC',
        bool $heavy = false,
    ): PivotResponse {
        $body = PivotRequest::create()
            ->dates($from, $to, $timezone)
            ->filter(PivotKeys::landingUuid($position), $landingUuids)
            ->groupBy(PivotKeys::landingUuid($position))
            ->toArray();

        return $this->aio->pivotReport($body, heavy: $heavy);
    }

    /**
     * MVT breakdown: filter by the tracked landing, group by every MVT custom
     * field it uses. Caller resolves Field models for those MVT slugs and
     * passes them in — `Field::clickhouseKey()` produces the right grouper key
     * (typically `string_fields[<uuid>]` for variant content fields).
     *
     * @param  list<Field>  $mvtFields
     */
    public function mvtBreakdown(
        string $landingUuid,
        int $position,
        array $mvtFields,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $timezone = 'UTC',
        bool $heavy = true,
    ): PivotResponse {
        $request = PivotRequest::create()
            ->dates($from, $to, $timezone)
            ->filter(PivotKeys::landingUuid($position), [$landingUuid]);

        foreach ($mvtFields as $field) {
            $request->groupBy($field->clickhouseKey());
        }

        return $this->aio->pivotReport($request->toArray(), heavy: $heavy);
    }
}
