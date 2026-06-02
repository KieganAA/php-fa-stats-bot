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

// Notification fan-out runs hourly. The command itself filters groups by
// their own notify_interval_minutes — see UserCompareGroup::isDueForPush.
// This is the knob that lets users pick 1h / 3h / 6h / 12h / 24h cadences.
Schedule::command('tracking:snapshot --no-capture')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
