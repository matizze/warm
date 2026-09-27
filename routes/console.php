<?php

use Illuminate\Support\Facades\Schedule;

if (config('warm.reindex.enabled')) {
    Schedule::command('warm:reindex')
        ->cron((string) config('warm.reindex.cron'))
        ->timezone((string) config('warm.reindex.timezone'))
        ->withoutOverlapping();
}
