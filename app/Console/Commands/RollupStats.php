<?php

namespace App\Console\Commands;

use App\Jobs\AggregateDailyStats;
use Illuminate\Console\Command;

class RollupStats extends Command
{
    protected $signature = 'linkforge:rollup {--days=2 : How many trailing days to recompute}';

    protected $description = 'Aggregate click_events into the daily_link_stats rollup table';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $this->info("Rolling up the last {$days} day(s)...");

        $rows = (new AggregateDailyStats($days))->handle();

        $this->info("Wrote {$rows} rollup row(s).");

        return self::SUCCESS;
    }
}
