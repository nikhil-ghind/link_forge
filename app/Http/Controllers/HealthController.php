<?php

namespace App\Http\Controllers;

use App\Services\ClickBuffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Load-balancer and on-call health probe.
 *
 * Reports Redis and MySQL separately because they fail differently: without
 * Redis the redirect path still works (slower, straight to MySQL); without
 * MySQL cached redirects keep serving but nothing new can be created.
 */
class HealthController extends Controller
{
    public function __invoke(ClickBuffer $clicks): JsonResponse
    {
        $redis = $this->probe(fn () => $clicks->depth());
        $mysql = $this->probe(fn () => DB::select('select 1'));

        $healthy = $redis['ok'] && $mysql['ok'];

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => [
                'redis' => $redis,
                'mysql' => $mysql,
            ],
            'click_buffer_depth' => $redis['ok'] ? $clicks->depth() : null,
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{ok: bool, latency_ms: float|null, error: string|null}
     */
    private function probe(callable $check): array
    {
        $start = microtime(true);

        try {
            $check();

            return [
                'ok' => true,
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => null, 'error' => $e->getMessage()];
        }
    }
}
