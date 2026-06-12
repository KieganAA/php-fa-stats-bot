<?php

namespace App\Services\Aio\Dto;

/**
 * One funnel step within an AIO campaign. Identified by its own AIO uuid
 * (the key in the `settings` dict). Order is `position` — 1-indexed by where
 * the step appears in AIO's settings dict; we don't have explicit LP numbers,
 * so this is the best we can do for stable labelling ("Step 1 split").
 *
 * `landingUuids` is the set of ACTIVE Landing-type items in this step (we
 * filter out inactive ones and non-Landing items — Forms, Allowance Rules,
 * Text fields and friends). >=2 means it's a traffic split.
 */
final class CampaignStep
{
    /**
     * @param  list<string>  $landingUuids  DISTINCT active landing uuids
     * @param  int  $activeItems  active Landing items BEFORE dedup — AIO lets
     *         the same landing appear several times on a step (weight rotation),
     *         so this can exceed count($landingUuids)
     * @param  int  $inactiveItems  Landing items present but toggled off
     */
    public function __construct(
        public readonly string $stepUuid,
        public readonly int $position,
        public readonly array $landingUuids,
        public readonly int $activeItems = 0,
        public readonly int $inactiveItems = 0,
    ) {}

    public function isSplit(): bool
    {
        return count($this->landingUuids) >= 2;
    }

    /** Active items that are repeats of a landing already counted. */
    public function duplicateItems(): int
    {
        return max(0, $this->activeItems - count($this->landingUuids));
    }

    /**
     * One human-readable line explaining what the analyzer saw on this step —
     * shown in subscribe summaries so "why is this not a split?" is answerable
     * at a glance: "шаг 2: 1 ленд (+2 дубля, 3 выкл)".
     */
    public function describe(): string
    {
        $unique = count($this->landingUuids);
        $bits = ["{$unique} ленд".($unique >= 2 ? 'а' : '')];
        if ($this->duplicateItems() > 0) {
            $bits[] = "+{$this->duplicateItems()} дубл.";
        }
        if ($this->inactiveItems > 0) {
            $bits[] = "{$this->inactiveItems} выкл.";
        }

        $detail = count($bits) > 1 ? $bits[0].' ('.implode(', ', array_slice($bits, 1)).')' : $bits[0];

        return "шаг {$this->position}: {$detail}".($this->isSplit() ? ' → сплит' : '');
    }
}
