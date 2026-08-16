<?php

namespace App\Console\Commands;

use App\Jobs\ProcessClickBatch;
use App\Services\ClickBuffer;
use App\Services\GeoResolver;
use App\Services\UserAgentParser;
use Illuminate\Console\Command;

class DrainClickBuffer extends Command
{
    protected $signature = 'linkforge:drain-clicks
        {--limit= : Maximum records to pull in one pass}
        {--loop : Keep draining until the buffer is empty}
        {--queue : Dispatch the drain to the queue instead of running inline}';

    protected $description = 'Drain buffered clicks from Redis into the click_events table';

    public function handle(ClickBuffer $buffer, UserAgentParser $agents, GeoResolver $geo): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($this->option('queue')) {
            ProcessClickBatch::dispatch($limit);
            $this->info('Dispatched a click drain to the queue.');

            return self::SUCCESS;
        }

        $total = 0;

        do {
            $job = new ProcessClickBatch($limit);
            $drained = $job->handle($buffer, $agents, $geo);
            $total += $drained;
        } while ($this->option('loop') && $drained > 0);

        $this->info(sprintf('Persisted %d click(s). Buffer depth is now %d.', $total, $buffer->depth()));

        return self::SUCCESS;
    }
}
