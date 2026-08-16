<?php

namespace App\Jobs;

use App\Services\ClickBuffer;
use App\Services\GeoResolver;
use App\Services\UserAgentParser;
use App\Support\ClickRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drains the Redis click buffer and bulk-inserts into click_events.
 *
 * Enrichment (UA parsing, referrer host extraction, geo, visitor hashing) all
 * happens here rather than on the redirect, and the write is a handful of
 * multi-row INSERTs instead of one INSERT per click.
 */
class ProcessClickBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly ?int $limit = null)
    {
        $this->onQueue((string) config('linkforge.clicks.queue', 'clicks'));
    }

    public function handle(
        ClickBuffer $buffer,
        UserAgentParser $agents,
        GeoResolver $geo,
    ): int {
        $buffer->trimOverflow();

        $records = $buffer->drain($this->limit);

        if ($records === []) {
            return 0;
        }

        $rows = [];
        $perLink = [];
        $lastClickAt = [];

        foreach ($records as $record) {
            $rows[] = $this->toRow($record, $agents, $geo);

            $perLink[$record->linkId] = ($perLink[$record->linkId] ?? 0) + 1;
            $lastClickAt[$record->linkId] = max($lastClickAt[$record->linkId] ?? 0, $record->timestamp);
        }

        $chunkSize = (int) config('linkforge.clicks.insert_chunk', 500);
        $inserted = 0;

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table('click_events')->insert($chunk);
            $inserted += count($chunk);
        }

        $this->updateLinkCounters($perLink, $lastClickAt);

        Log::channel('worker')->info('linkforge.clicks.drained', [
            'records' => $inserted,
            'links' => count($perLink),
            'remaining' => $buffer->depth(),
        ]);

        return $inserted;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(ClickRecord $record, UserAgentParser $agents, GeoResolver $geo): array
    {
        $ua = $agents->parse($record->userAgent);
        $storeIp = (bool) config('linkforge.clicks.store_ip', false);

        return [
            'link_id' => $record->linkId,
            'clicked_at' => gmdate('Y-m-d H:i:s', $record->timestamp),
            'referrer_host' => $this->referrerHost($record->referrer),
            'referrer_url' => $record->referrer,
            'country' => $geo->resolve($record->country, $record->ip),
            'device_type' => $ua['device_type'],
            'browser' => $ua['browser'],
            'os' => $ua['os'],
            'user_agent' => $record->userAgent,
            'ip_address' => $storeIp ? $record->ip : null,
            'visitor_hash' => $geo->visitorHash($record->ip, $record->userAgent),
            'is_bot' => $ua['is_bot'],
        ];
    }

    /**
     * Direct traffic (no referer header) is recorded as `direct` rather than
     * NULL so the breakdown query does not have to special-case it.
     */
    private function referrerHost(?string $referrer): string
    {
        if ($referrer === null || $referrer === '') {
            return 'direct';
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return 'direct';
        }

        return strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
    }

    /**
     * Fold the batch into the denormalised per-link counters with one UPDATE
     * per link rather than one per click.
     *
     * @param  array<int, int>  $perLink
     * @param  array<int, int>  $lastClickAt
     */
    private function updateLinkCounters(array $perLink, array $lastClickAt): void
    {
        foreach ($perLink as $linkId => $count) {
            DB::table('links')
                ->where('id', $linkId)
                ->update([
                    'click_count' => DB::raw('click_count + '.(int) $count),
                    'last_clicked_at' => gmdate('Y-m-d H:i:s', $lastClickAt[$linkId] ?? time()),
                ]);
        }
    }
}
