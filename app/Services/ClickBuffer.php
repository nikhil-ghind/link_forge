<?php

namespace App\Services;

use App\Support\ClickRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write-behind click tracking.
 *
 * The redirect response must not wait on MySQL, so a click is one pipelined
 * Redis round-trip: RPUSH the record onto a buffer list and INCR three
 * counters (per-link total, per-link day, global day). A queue worker drains
 * the list in batches and bulk-inserts into click_events.
 *
 * The counters are what the dashboard reads for "right now" numbers, so live
 * totals are correct even between drains.
 */
class ClickBuffer
{
    public function __construct(private readonly RedisLinkStore $store) {}

    public function record(ClickRecord $click): void
    {
        $payload = $click->encode();

        if ($payload === '') {
            return;
        }

        $date = gmdate('Y-m-d', $click->timestamp);
        $counterTtl = (int) config('linkforge.clicks.counter_ttl', 172_800);

        try {
            $this->store->pipeline(function ($pipe) use ($payload, $click, $date, $counterTtl) {
                $pipe->rpush($this->store->bufferKey(), [$payload]);

                $totalKey = $this->store->totalClicksKey($click->linkId);
                $dayKey = $this->store->dailyClicksKey($click->linkId, $date);
                $globalKey = $this->store->globalDailyClicksKey($date);

                $pipe->incr($totalKey);
                $pipe->incr($dayKey);
                $pipe->expire($dayKey, $counterTtl);
                $pipe->incr($globalKey);
                $pipe->expire($globalKey, $counterTtl);
            });
        } catch (Throwable $e) {
            // Losing a click is strictly better than failing a redirect.
            Log::warning('linkforge.clicks.buffer_failed', [
                'link_id' => $click->linkId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pull up to $limit records off the head of the buffer atomically.
     *
     * LRANGE + LTRIM inside a transaction gives an at-most-once drain: if the
     * worker dies after the trim the batch is lost, which is the right
     * trade-off for click telemetry (the Redis counters stay authoritative for
     * totals). Making it at-least-once would risk double-counting instead.
     *
     * @return array<int, ClickRecord>
     */
    public function drain(?int $limit = null): array
    {
        $limit ??= (int) config('linkforge.clicks.drain_batch', 2_000);
        $key = $this->store->bufferKey();

        $results = $this->store->connection()->transaction(function ($tx) use ($key, $limit) {
            $tx->lrange($key, 0, $limit - 1);
            $tx->ltrim($key, $limit, -1);
        });

        $raw = $results[0] ?? [];

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $records = [];

        foreach ($raw as $entry) {
            $record = ClickRecord::decode((string) $entry);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function depth(): int
    {
        return (int) $this->store->connection()->llen($this->store->bufferKey());
    }

    /**
     * Guard against unbounded growth if the worker has been down: drop the
     * oldest overflow rather than letting Redis hit maxmemory and start
     * evicting the link cache.
     */
    public function trimOverflow(): int
    {
        $max = (int) config('linkforge.clicks.max_buffer', 500_000);
        $depth = $this->depth();

        if ($depth <= $max) {
            return 0;
        }

        $overflow = $depth - $max;
        $this->store->connection()->ltrim($this->store->bufferKey(), $overflow, -1);

        Log::warning('linkforge.clicks.buffer_overflow', ['dropped' => $overflow, 'depth' => $depth]);

        return $overflow;
    }

    public function liveClicks(int $linkId): int
    {
        return $this->store->counter($this->store->totalClicksKey($linkId));
    }

    /**
     * @param  array<int, int>  $linkIds
     * @return array<int, int>  keyed by link id
     */
    public function liveClicksFor(array $linkIds): array
    {
        if ($linkIds === []) {
            return [];
        }

        $keys = array_map(fn (int $id) => $this->store->totalClicksKey($id), $linkIds);
        $values = $this->store->mget($keys);

        $out = [];

        foreach (array_values($linkIds) as $i => $id) {
            $out[$id] = (int) ($values[$i] ?? 0);
        }

        return $out;
    }

    public function clicksOnDay(int $linkId, string $date): int
    {
        return $this->store->counter($this->store->dailyClicksKey($linkId, $date));
    }

    public function globalClicksOnDay(string $date): int
    {
        return $this->store->counter($this->store->globalDailyClicksKey($date));
    }
}
