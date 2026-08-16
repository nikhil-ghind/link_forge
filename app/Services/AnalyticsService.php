<?php

namespace App\Services;

use App\Models\ClickEvent;
use App\Models\DailyLinkStat;
use App\Models\Link;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-side analytics.
 *
 * Two sources feed every answer:
 *
 *   1. `click_events`      — full fidelity, used for ranges short enough to scan
 *                            (<= linkforge.analytics.rollup_threshold_days).
 *   2. `daily_link_stats`  — pre-aggregated per link/day, used for long ranges
 *                            so a year-long chart is a few hundred row reads.
 *
 * On top of either, the live Redis counters are merged in for the current day,
 * so the dashboard is accurate to the second even though clicks are persisted
 * asynchronously.
 */
class AnalyticsService
{
    public function __construct(
        private readonly ClickBuffer $clicks,
    ) {}

    /**
     * Headline KPIs with period-over-period deltas.
     *
     * @return array<string, mixed>
     */
    public function summary(int $days, ?int $linkId = null): array
    {
        [$from, $to] = $this->window($days);
        [$prevFrom, $prevTo] = [$from->copy()->subDays($days), $from];

        $current = $this->totalClicks($from, $to, $linkId);
        $previous = $this->totalClicks($prevFrom, $prevTo, $linkId);

        $today = $this->liveClicksToday($linkId);
        $uniques = $this->uniqueVisitors($from, $to, $linkId);

        $links = Link::query()->when($linkId !== null, fn ($q) => $q->whereKey($linkId));

        return [
            'range_days' => $days,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'clicks' => $current,
            'previous_clicks' => $previous,
            'clicks_delta_pct' => $this->deltaPct($current, $previous),
            'clicks_today' => $today,
            'unique_visitors' => $uniques,
            'total_links' => (clone $links)->count(),
            'active_links' => (clone $links)->active()->count(),
            'links_created_in_range' => (clone $links)->where('created_at', '>=', $from)->count(),
            'avg_clicks_per_link' => $this->avgClicksPerLink($current, (clone $links)->count()),
            'buffer_depth' => $this->clicks->depth(),
        ];
    }

    /**
     * Click time series. Hourly granularity for <= 2 days, daily beyond that.
     *
     * @return array<int, array{bucket: string, clicks: int}>
     */
    public function timeseries(int $days, ?int $linkId = null, ?string $granularity = null): array
    {
        [$from, $to] = $this->window($days);
        $granularity ??= $days <= 2 ? 'hour' : 'day';

        $rows = $granularity === 'hour'
            ? $this->hourlySeries($from, $to, $linkId)
            : $this->dailySeries($from, $to, $days, $linkId);

        return $this->fillGaps($rows, $from, $to, $granularity, $linkId);
    }

    /**
     * @return array<int, array{bucket: string, clicks: int}>
     */
    private function hourlySeries(Carbon $from, Carbon $to, ?int $linkId): array
    {
        return ClickEvent::query()
            ->selectRaw($this->dateFormatExpression('clicked_at', 'hour').' as bucket, count(*) as clicks')
            ->between($from, $to)
            ->when($linkId !== null, fn ($q) => $q->where('link_id', $linkId))
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => ['bucket' => (string) $row->bucket, 'clicks' => (int) $row->clicks])
            ->all();
    }

    /**
     * @return array<int, array{bucket: string, clicks: int}>
     */
    private function dailySeries(Carbon $from, Carbon $to, int $days, ?int $linkId): array
    {
        if ($this->useRollups($days)) {
            return DailyLinkStat::query()
                ->selectRaw('stat_date as bucket, sum(clicks) as clicks')
                ->between($from->toDateString(), $to->toDateString())
                ->when($linkId !== null, fn ($q) => $q->where('link_id', $linkId))
                ->groupBy('stat_date')
                ->orderBy('stat_date')
                ->get()
                ->map(fn ($row) => ['bucket' => Carbon::parse((string) $row->bucket)->toDateString(), 'clicks' => (int) $row->clicks])
                ->all();
        }

        return ClickEvent::query()
            ->selectRaw($this->dateFormatExpression('clicked_at', 'day').' as bucket, count(*) as clicks')
            ->between($from, $to)
            ->when($linkId !== null, fn ($q) => $q->where('link_id', $linkId))
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => ['bucket' => (string) $row->bucket, 'clicks' => (int) $row->clicks])
            ->all();
    }

    /**
     * Zero-fill empty buckets so the chart has no gaps, and overlay the live
     * Redis counter onto today's bucket.
     *
     * @param  array<int, array{bucket: string, clicks: int}>  $rows
     * @return array<int, array{bucket: string, clicks: int}>
     */
    private function fillGaps(array $rows, Carbon $from, Carbon $to, string $granularity, ?int $linkId): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row['bucket']] = $row['clicks'];
        }

        $out = [];
        $cursor = $granularity === 'hour' ? $from->copy()->startOfHour() : $from->copy()->startOfDay();
        $step = $granularity === 'hour' ? 'addHour' : 'addDay';
        $format = $granularity === 'hour' ? 'Y-m-d H:00' : 'Y-m-d';

        while ($cursor < $to) {
            $key = $cursor->format($format);
            $clicks = $indexed[$key] ?? 0;

            if ($granularity === 'day' && $cursor->isSameDay($to)) {
                $clicks = max($clicks, $this->liveClicksOn($cursor->toDateString(), $linkId));
            }

            $out[] = ['bucket' => $key, 'clicks' => $clicks];
            $cursor->{$step}();
        }

        return $out;
    }

    /**
     * @return array<int, array{link_id: int, slug: string, title: string|null, short_url: string, clicks: int}>
     */
    public function topLinks(int $days, int $limit = 10): array
    {
        [$from, $to] = $this->window($days);
        $limit = min(max($limit, 1), 50);

        $counts = $this->useRollups($days)
            ? DailyLinkStat::query()
                ->selectRaw('link_id, sum(clicks) as clicks')
                ->between($from->toDateString(), $to->toDateString())
                ->groupBy('link_id')
                ->orderByDesc('clicks')
                ->limit($limit)
                ->pluck('clicks', 'link_id')
            : ClickEvent::query()
                ->selectRaw('link_id, count(*) as clicks')
                ->between($from, $to)
                ->groupBy('link_id')
                ->orderByDesc('clicks')
                ->limit($limit)
                ->pluck('clicks', 'link_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $links = Link::query()->whereIn('id', $counts->keys())->get()->keyBy('id');

        return $counts
            ->map(fn ($clicks, $linkId) => [
                'link_id' => (int) $linkId,
                'slug' => (string) ($links[$linkId]->slug ?? ''),
                'title' => $links[$linkId]->title ?? null,
                'short_url' => $links[$linkId]?->shortUrl() ?? '',
                'clicks' => (int) $clicks,
            ])
            ->values()
            ->all();
    }

    /**
     * Breakdown of clicks by a categorical dimension.
     *
     * @return array<int, array{label: string, clicks: int, share: float}>
     */
    public function breakdown(string $dimension, int $days, ?int $linkId = null, int $limit = 10): array
    {
        $column = match ($dimension) {
            'referrer' => 'referrer_host',
            'country' => 'country',
            'device' => 'device_type',
            'browser' => 'browser',
            'os' => 'os',
            default => throw new \InvalidArgumentException("Unsupported breakdown dimension [{$dimension}]."),
        };

        [$from, $to] = $this->window($days);

        $rows = ClickEvent::query()
            ->selectRaw("coalesce({$column}, 'unknown') as label, count(*) as clicks")
            ->between($from, $to)
            ->when($linkId !== null, fn ($q) => $q->where('link_id', $linkId))
            ->groupBy('label')
            ->orderByDesc('clicks')
            ->limit($limit)
            ->get();

        $total = max(1, (int) $rows->sum('clicks'));

        return $rows->map(fn ($row) => [
            'label' => (string) $row->label,
            'clicks' => (int) $row->clicks,
            'share' => round(((int) $row->clicks / $total) * 100, 1),
        ])->all();
    }

    /**
     * Per-link detail combining its series, breakdowns and live counters.
     *
     * @return array<string, mixed>
     */
    public function linkStats(Link $link, int $days): array
    {
        return [
            'link_id' => $link->id,
            'slug' => $link->slug,
            'short_url' => $link->shortUrl(),
            'target_url' => $link->target_url,
            'summary' => $this->summary($days, $link->id),
            'timeseries' => $this->timeseries($days, $link->id),
            'referrers' => $this->breakdown('referrer', $days, $link->id),
            'countries' => $this->breakdown('country', $days, $link->id),
            'devices' => $this->breakdown('device', $days, $link->id),
            'browsers' => $this->breakdown('browser', $days, $link->id),
        ];
    }

    private function totalClicks(Carbon $from, Carbon $to, ?int $linkId): int
    {
        $days = max(1, (int) $from->diffInDays($to));

        if ($this->useRollups($days)) {
            return (int) DailyLinkStat::query()
                ->between($from->toDateString(), $to->toDateString())
                ->when($linkId !== null, fn ($q) => $q->where('link_id', $linkId))
                ->sum('clicks');
        }

        return (int) ClickEvent::query()
            ->between($from, $to)
            ->when($linkId !== null, fn ($q) => $q->where('link_id', $linkId))
            ->count();
    }

    private function uniqueVisitors(Carbon $from, Carbon $to, ?int $linkId): int
    {
        return (int) ClickEvent::query()
            ->between($from, $to)
            ->when($linkId !== null, fn ($q) => $q->where('link_id', $linkId))
            ->distinct()
            ->count(DB::raw('visitor_hash'));
    }

    private function liveClicksToday(?int $linkId): int
    {
        return $this->liveClicksOn(gmdate('Y-m-d'), $linkId);
    }

    private function liveClicksOn(string $date, ?int $linkId): int
    {
        return $linkId === null
            ? $this->clicks->globalClicksOnDay($date)
            : $this->clicks->clicksOnDay($linkId, $date);
    }

    private function avgClicksPerLink(int $clicks, int $links): float
    {
        return $links === 0 ? 0.0 : round($clicks / $links, 2);
    }

    private function deltaPct(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null; // null renders as "new" rather than a bogus infinity
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function useRollups(int $days): bool
    {
        return $days > (int) config('linkforge.analytics.rollup_threshold_days', 14);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(int $days): array
    {
        $days = min(max($days, 1), (int) config('linkforge.analytics.max_range_days', 400));

        $to = Carbon::now('UTC');

        return [$to->copy()->subDays($days)->startOfDay(), $to];
    }

    /**
     * Bucket expression per driver. MySQL is the deployment target; the SQLite
     * branch exists so the test suite can run without a database server.
     */
    private function dateFormatExpression(string $column, string $granularity): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $format = $granularity === 'hour' ? '%Y-%m-%d %H:00' : '%Y-%m-%d';

            return "strftime('{$format}', {$column})";
        }

        $format = $granularity === 'hour' ? '%Y-%m-%d %H:00' : '%Y-%m-%d';

        return "date_format({$column}, '{$format}')";
    }
}
