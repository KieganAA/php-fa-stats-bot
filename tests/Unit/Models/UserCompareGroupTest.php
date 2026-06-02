<?php

namespace Tests\Unit\Models;

use App\Models\UserCompareGroup;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Coverage for UserCompareGroup::isDueForPush — the gate the scheduler uses
 * to honour per-group intervals. Eloquent attribute casts pull from the
 * container at write-time, so we boot the framework via Tests\TestCase
 * rather than the plain PHPUnit base.
 */
class UserCompareGroupTest extends TestCase
{
    public function test_paused_groups_are_never_due(): void
    {
        $g = $this->group(paused: true, lastNotifiedAt: null);

        $this->assertFalse($g->isDueForPush(CarbonImmutable::now()));
    }

    public function test_never_notified_is_always_due(): void
    {
        $g = $this->group(paused: false, lastNotifiedAt: null);

        $this->assertTrue($g->isDueForPush(CarbonImmutable::now()));
    }

    public function test_interval_not_yet_elapsed_skips(): void
    {
        $now = CarbonImmutable::create(2026, 5, 22, 12, 0, 0, 'UTC');
        $g = $this->group(
            paused: false,
            lastNotifiedAt: $now->copy()->subMinutes(30),
            interval: 180,
        );

        $this->assertFalse($g->isDueForPush($now));
    }

    public function test_interval_elapsed_fires(): void
    {
        $now = CarbonImmutable::create(2026, 5, 22, 12, 0, 0, 'UTC');
        $g = $this->group(
            paused: false,
            lastNotifiedAt: $now->copy()->subMinutes(181),
            interval: 180,
        );

        $this->assertTrue($g->isDueForPush($now));
    }

    public function test_exact_boundary_fires(): void
    {
        $now = CarbonImmutable::create(2026, 5, 22, 12, 0, 0, 'UTC');
        $g = $this->group(
            paused: false,
            lastNotifiedAt: $now->copy()->subMinutes(180),
            interval: 180,
        );

        $this->assertTrue($g->isDueForPush($now), 'exactly N minutes ago should fire');
    }

    public function test_zero_or_null_interval_falls_back_to_default(): void
    {
        $now = CarbonImmutable::create(2026, 5, 22, 12, 0, 0, 'UTC');
        $g = $this->group(
            paused: false,
            lastNotifiedAt: $now->copy()->subMinutes(120),
            interval: null,
        );
        // Default is 180 → 120 minutes ago is NOT yet due.
        $this->assertFalse($g->isDueForPush($now));
    }

    private function group(bool $paused, ?CarbonImmutable $lastNotifiedAt, ?int $interval = 180): UserCompareGroup
    {
        $g = new UserCompareGroup;
        $g->paused_at = $paused ? CarbonImmutable::now() : null;
        $g->last_notified_at = $lastNotifiedAt;
        $g->notify_interval_minutes = $interval;

        return $g;
    }
}
