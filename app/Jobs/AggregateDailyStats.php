<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rolls raw click_events up into daily_link_stats.
 *
 * Idempotent by construction: each (link_id, stat_date) pair is recomputed and
 * upserted, so re-running for the same day converges rather than double-counts.
 * The job runs hourly for the last couple of days, which covers late drains
 * landing after midnight.
 */
class AggregateDailyStats implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public readonly int $daysBack = 2) {}

    public function handle(): int
    {
        $rowsWritten = 0;

        for ($offset = $this->daysBack - 1; $offset >= 0; $offset--) {
            $date = Carbon::now('UTC')->subDays($offset)->toDateString();
            $rowsWritten += $this->aggregateDay($date);
        }

        Log::channel('worker')->info('linkforge.rollup.complete', [
            'days' => $this->daysBack,
            'rows' => $rowsWritten,
        ]);

        return $rowsWritten;
    }

    public function aggregateDay(string $date): int
    {
        $start = Carbon::parse($date, 'UTC')->startOfDay();
        $end = $start->copy()->addDay();

        $totals = DB::table('click_events')
            ->selectRaw('link_id, count(*) as clicks')
            ->selectRaw('count(distinct visitor_hash) as unique_visitors')
            ->selectRaw('sum(case when is_bot = 1 then 1 else 0 end) as bot_clicks')
            ->where('clicked_at', '>=', $start)
            ->where('clicked_at', '<', $end)
            ->groupBy('link_id')
            ->get();

        if ($totals->isEmpty()) {
            return 0;
        }

        $dimensions = [
            'referrers' => $this->topValues('referrer_host', $start, $end),
            'countries' => $this->topValues('country', $start, $end),
            'devices' => $this->topValues('device_type', $start, $end),
            'browsers' => $this->topValues('browser', $start, $end),
        ];

        $now = now();
        $rows = [];

        foreach ($totals as $total) {
            $linkId = (int) $total->link_id;

            $rows[] = [
                'link_id' => $linkId,
                'stat_date' => $date,
                'clicks' => (int) $total->clicks,
                'unique_visitors' => (int) $total->unique_visitors,
                'bot_clicks' => (int) $total->bot_clicks,
                'referrers' => json_encode($dimensions['referrers'][$linkId] ?? []),
                'countries' => json_encode($dimensions['countries'][$linkId] ?? []),
                'devices' => json_encode($dimensions['devices'][$linkId] ?? []),
                'browsers' => json_encode($dimensions['browsers'][$linkId] ?? []),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('daily_link_stats')->upsert(
                $chunk,
                ['link_id', 'stat_date'],
                ['clicks', 'unique_visitors', 'bot_clicks', 'referrers', 'countries', 'devices', 'browsers', 'updated_at']
            );
        }

        return count($rows);
    }

    /**
     * Top values of one categorical column per link, kept small so the JSON
     * column stays cheap to read.
     *
     * @return array<int, array<string, int>>
     */
    private function topValues(string $column, Carbon $start, Carbon $end, int $perLink = 8): array
    {
        $rows = DB::table('click_events')
            ->selectRaw("link_id, coalesce({$column}, 'unknown') as label, count(*) as clicks")
            ->where('clicked_at', '>=', $start)
            ->where('clicked_at', '<', $end)
            ->groupBy('link_id', 'label')
            ->orderByDesc('clicks')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $linkId = (int) $row->link_id;

            if (count($out[$linkId] ?? []) >= $perLink) {
                continue;
            }

            $out[$linkId][(string) $row->label] = (int) $row->clicks;
        }

        return $out;
    }
}
