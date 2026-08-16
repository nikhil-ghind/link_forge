<?php

namespace App\Console\Commands;

use App\Services\LinkCache;
use Illuminate\Console\Command;

class WarmLinkCache extends Command
{
    protected $signature = 'linkforge:warm-cache {--limit=1000 : How many of the hottest links to preload}';

    protected $description = 'Preload the hottest links into the Redis redirect cache';

    public function handle(LinkCache $cache): int
    {
        $limit = (int) $this->option('limit');

        $this->info("Warming the redirect cache with the top {$limit} links by click count...");

        $warmed = $cache->warm($limit);

        $this->info("Warmed {$warmed} link(s).");

        return self::SUCCESS;
    }
}
