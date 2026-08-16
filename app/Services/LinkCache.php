<?php

namespace App\Services;

use App\Models\Link;
use App\Support\ResolvedLink;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cache-aside resolution of slug -> link for the redirect hot path.
 *
 * Design notes:
 *
 *  - A hit is exactly one Redis GET. MySQL is never touched.
 *  - A miss takes a short per-slug lock so a cold-but-popular slug produces one
 *    database read instead of N concurrent identical reads (cache stampede).
 *  - An unknown slug writes a MISS sentinel with a short TTL. Without this, a
 *    scanner walking the slug space would turn every 404 into a MySQL query.
 *  - Positive TTLs get random jitter so entries written in the same burst do
 *    not all expire in the same second.
 *  - Redis being unavailable degrades to a direct MySQL read rather than an
 *    error page; the redirect stays correct, it just gets slower.
 */
class LinkCache
{
    public function __construct(private readonly RedisLinkStore $store) {}

    public function resolve(string $slug): ?ResolvedLink
    {
        $key = $this->store->slugKey($slug);

        try {
            $cached = $this->store->get($key);
        } catch (Throwable $e) {
            Log::warning('linkforge.cache.unavailable', ['slug' => $slug, 'error' => $e->getMessage()]);

            return $this->readThrough($slug);
        }

        if ($cached !== null) {
            return $this->decode($cached);
        }

        return $this->resolveOnMiss($slug, $key);
    }

    private function resolveOnMiss(string $slug, string $key): ?ResolvedLink
    {
        $lockKey = $this->store->lockKey($slug);
        $lockTtl = (int) config('linkforge.cache.lock_ttl', 5);

        if (! $this->store->acquireLock($lockKey, $lockTtl)) {
            // Someone else is rebuilding this entry. Spin briefly on the cache
            // rather than piling another query onto MySQL.
            $resolved = $this->awaitRebuild($key);

            if ($resolved !== false) {
                return $resolved;
            }
        }

        try {
            // Re-check: the lock holder may have populated the key while we
            // were waiting to acquire it.
            $cached = $this->store->get($key);

            if ($cached !== null) {
                return $this->decode($cached);
            }

            $link = $this->fetchFromDatabase($slug);

            if ($link === null) {
                $this->rememberMiss($slug);

                return null;
            }

            $resolved = ResolvedLink::fromModel($link);
            $this->put($resolved);

            return $resolved;
        } finally {
            $this->store->releaseLock($lockKey);
        }
    }

    /**
     * Poll the cache for a short window while another worker rebuilds it.
     *
     * @return ResolvedLink|null|false  false means "still not there, go rebuild"
     */
    private function awaitRebuild(string $key): ResolvedLink|null|false
    {
        $retries = (int) config('linkforge.cache.lock_retries', 4);
        $waitUs = ((int) config('linkforge.cache.lock_wait_ms', 40)) * 1000;

        for ($i = 0; $i < $retries; $i++) {
            usleep($waitUs);

            $cached = $this->store->get($key);

            if ($cached !== null) {
                return $this->decode($cached);
            }
        }

        return false;
    }

    private function decode(string $cached): ?ResolvedLink
    {
        if ($cached === $this->missSentinel()) {
            return null;
        }

        $payload = json_decode($cached, true);

        if (! is_array($payload)) {
            return null;
        }

        return ResolvedLink::fromArray($payload);
    }

    /**
     * Direct MySQL read used when Redis is down. No caching is attempted.
     */
    private function readThrough(string $slug): ?ResolvedLink
    {
        $link = $this->fetchFromDatabase($slug);

        return $link === null ? null : ResolvedLink::fromModel($link);
    }

    private function fetchFromDatabase(string $slug): ?Link
    {
        return Link::query()
            ->select(['id', 'slug', 'target_url', 'redirect_status', 'is_active', 'expires_at', 'max_clicks'])
            ->where('slug', $slug)
            ->first();
    }

    public function put(ResolvedLink $link): void
    {
        $payload = json_encode($link->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return;
        }

        try {
            $this->store->setex($this->store->slugKey($link->slug), $this->positiveTtl(), $payload);
        } catch (Throwable $e) {
            Log::warning('linkforge.cache.write_failed', ['slug' => $link->slug, 'error' => $e->getMessage()]);
        }
    }

    public function rememberMiss(string $slug): void
    {
        $ttl = (int) config('linkforge.cache.negative_ttl', 60);

        try {
            $this->store->setex($this->store->slugKey($slug), $ttl, $this->missSentinel());
        } catch (Throwable $e) {
            Log::warning('linkforge.cache.negative_write_failed', ['slug' => $slug, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Invalidate after an update/delete. Called by the link management API.
     */
    public function forget(string $slug): void
    {
        try {
            $this->store->del($this->store->slugKey($slug));
        } catch (Throwable $e) {
            Log::warning('linkforge.cache.forget_failed', ['slug' => $slug, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Proactively warm the cache — used by the deploy hook after a cold Redis
     * so the first traffic wave does not all miss at once.
     */
    public function warm(int $limit = 1000): int
    {
        $warmed = 0;

        Link::query()
            ->active()
            ->orderByDesc('click_count')
            ->limit($limit)
            ->each(function (Link $link) use (&$warmed) {
                $this->put(ResolvedLink::fromModel($link));
                $warmed++;
            });

        return $warmed;
    }

    private function positiveTtl(): int
    {
        $base = (int) config('linkforge.cache.ttl', 86_400);
        $jitter = (int) config('linkforge.cache.ttl_jitter', 3_600);

        return $jitter > 0 ? $base + random_int(0, $jitter) : $base;
    }

    private function missSentinel(): string
    {
        return (string) config('linkforge.cache.miss_sentinel', "\0MISS");
    }
}
