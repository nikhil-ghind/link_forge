<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function summary(Request $request): JsonResponse
    {
        $days = $this->days($request);

        // The dashboard polls this every few seconds; a short cache keeps a
        // room full of open dashboards from re-running the aggregates.
        $data = Cache::remember(
            "analytics:summary:{$days}",
            (int) config('linkforge.analytics.summary_cache_ttl', 30),
            fn () => $this->analytics->summary($days)
        );

        return response()->json(['data' => $data]);
    }

    public function timeseries(Request $request): JsonResponse
    {
        $granularity = in_array($request->query('granularity'), ['hour', 'day'], true)
            ? (string) $request->query('granularity')
            : null;

        return response()->json([
            'data' => $this->analytics->timeseries($this->days($request), null, $granularity),
        ]);
    }

    public function topLinks(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', (int) config('linkforge.analytics.top_limit', 10));

        return response()->json([
            'data' => $this->analytics->topLinks($this->days($request), $limit),
        ]);
    }

    public function breakdown(Request $request, string $dimension): JsonResponse
    {
        if (! in_array($dimension, ['referrer', 'country', 'device', 'browser', 'os'], true)) {
            return response()->json(['message' => "Unsupported dimension [{$dimension}]."], 422);
        }

        $linkId = $request->query('link_id') !== null ? (int) $request->query('link_id') : null;
        $limit = min(max((int) $request->query('limit', 10), 1), 50);

        return response()->json([
            'data' => $this->analytics->breakdown($dimension, $this->days($request), $linkId, $limit),
        ]);
    }

    public function linkStats(Request $request, Link $link): JsonResponse
    {
        return response()->json([
            'data' => $this->analytics->linkStats($link, $this->days($request)),
        ]);
    }

    private function days(Request $request): int
    {
        $days = (int) $request->query('days', (int) config('linkforge.analytics.default_range_days', 30));

        return min(max($days, 1), (int) config('linkforge.analytics.max_range_days', 400));
    }
}
