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
