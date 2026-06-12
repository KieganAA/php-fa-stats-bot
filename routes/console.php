<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('aio:sync:all')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('mvt:slice', ['--broadcast'])
    ->cron('0 */3 * * *')
    ->withoutOverlapping()
    ->runInBackground();

// Snapshot capture stays on the 3h cadence — it's only used for the trend
// history surface (no per-group config to honor here).
Schedule::command('tracking:snapshot --no-notify')
    ->cron('0 */3 * * *')
    ->withoutOverlapping()
    ->runInBackground();

// Notification fan-out. The command itself filters groups by their own
// schedule (notify_interval_minutes OR daily_at) — see
// UserCompareGroup::isDueForPush. Every 15 minutes so "daily at HH:MM"
// schedules fire within a quarter hour of the chosen time; interval groups
// are unaffected (they only fire when their own interval has elapsed).
Schedule::command('tracking:snapshot --no-capture')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
