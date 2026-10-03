<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh every target that has not been attempted in the last hour (see SyncDueTargets).
Schedule::command('sync:targets')
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command('sync:recover')->everyFiveMinutes()->withoutOverlapping(10);
