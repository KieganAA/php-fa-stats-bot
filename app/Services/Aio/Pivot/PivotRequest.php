<?php

namespace App\Services\Aio\Pivot;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Typed builder for POST /api/v1/pivot-report/data body.
 *
 * Body is flat (no `{request: ...}` wrapper) per AIO docs. All boolean
 * switches have sensible defaults; only `dates` has no default.
 */
class PivotRequest
{
    /** @var list<array{key: string, values: list<string>}> */
    private array $conditions = [];

    /** @var list<array{key: string}> */
    private array $definitions = [];

    private ?string $from = null;

    private ?string $to = null;

    private string $timezone = 'UTC';

    private bool $hideBots = true;

    private bool $hideTrash = true;

    private bool $hideEmptyMetrics = true;

    private bool $backFixAttribution = false;

    private bool $eventTimeAttribution = false;

    public static function create(): self
    {
        return new self;
    }

    public function dates(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $timezone = 'UTC',
    ): self {
        $this->from = self::normalizeDate($from);
        $this->to = self::normalizeDate($to);
        $this->timezone = $timezone;

        return $this;
    }

    public function lastHours(int $hours, string $timezone = 'UTC'): self
    {
        $now = new DateTimeImmutable('now');
        $from = $now->modify("-{$hours} hours");

        return $this->dates($from, $now, $timezone);
    }

    public function lastDays(int $days, string $timezone = 'UTC'): self
    {
        $now = new DateTimeImmutable('now');
        $from = $now->modify("-{$days} days");

        return $this->dates($from, $now, $timezone);
    }

    /** @param  list<string>  $values */
    public function filter(string $key, array $values): self
    {
        if ($values === []) {
            return $this;
        }

        $this->conditions[] = [
            'key' => $key,
            'values' => array_values(array_map('strval', $values)),
        ];

        return $this;
    }

    public function groupBy(string $key): self
    {
        $this->definitions[] = ['key' => $key];

        return $this;
    }

    /** @param  list<string>  $keys */
    public function groupByMany(array $keys): self
    {
        foreach ($keys as $key) {
            $this->groupBy($key);
        }

        return $this;
    }

    public function hideBots(bool $on): self
    {
        $this->hideBots = $on;

        return $this;
    }

    public function hideTrash(bool $on): self
    {
        $this->hideTrash = $on;

        return $this;
    }

    public function hideEmptyMetrics(bool $on): self
    {
        $this->hideEmptyMetrics = $on;

        return $this;
    }

    public function backFixAttribution(bool $on): self
    {
        $this->backFixAttribution = $on;

        return $this;
    }

    public function eventTimeAttribution(bool $on): self
    {
        $this->eventTimeAttribution = $on;

        return $this;
    }

    public function toArray(): array
    {
        if ($this->from === null || $this->to === null) {
            throw new InvalidArgumentException('PivotRequest requires dates() to be called before toArray().');
        }

        if (count($this->definitions) === 0) {
            throw new InvalidArgumentException('PivotRequest requires at least one groupBy() (AIO requires 1..7 definitions).');
        }

        if (count($this->definitions) > 7) {
            throw new InvalidArgumentException('AIO rejects more than 7 definitions (got '.count($this->definitions).').');
        }

        return [
            'dates' => [$this->from, $this->to, $this->timezone],
            'back_fix_attribution' => $this->backFixAttribution,
            'event_time_attribution' => $this->eventTimeAttribution,
            'hide_bots' => $this->hideBots,
            'hide_empty_metrics' => $this->hideEmptyMetrics,
            'hide_trash' => $this->hideTrash,
            'conditions' => $this->conditions,
            'definitions' => $this->definitions,
        ];
    }

    private static function normalizeDate(DateTimeInterface|string $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
