<?php

use App\Jobs\AggregateDailyStats;
use App\Jobs\ProcessClickBatch;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| The queue worker normally drains clicks continuously; this schedule is the
| safety net that keeps the buffer moving if the worker restarts, plus the
| nightly rollup that feeds long-range analytics.
|
*/

Schedule::job(new ProcessClickBatch)
    ->everyMinute()
    ->withoutOverlapping()
    ->name('drain-click-buffer');

// Re-aggregate yesterday and today: today because it is still accumulating,
// yesterday because a late drain can land after midnight.
Schedule::job(new AggregateDailyStats(daysBack: 2))
    ->hourly()
    ->withoutOverlapping()
    ->name('rollup-daily-stats');

Schedule::command('linkforge:warm-cache --limit=2000')
    ->dailyAt('03:30')
    ->name('warm-link-cache');

Schedule::command('queue:prune-failed --hours=336')->weekly();
