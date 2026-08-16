<?php

namespace App\Services;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Thin wrapper over the dedicated `links` Redis connection.
 *
 * Every hot-path Redis call in LinkForge goes through here so the key layout,
 * the connection choice and the pipelining behaviour live in exactly one file.
 */
class RedisLinkStore
{
    public function __construct(private readonly string $connection = 'links') {}

    public function connection(): Connection
    {
        return Redis::connection($this->connection);
    }

    public function prefix(): string
    {
        return (string) config('linkforge.cache.prefix', 'lf');
    }

    public function slugKey(string $slug): string
    {
        return $this->prefix().':slug:'.$slug;
    }

    public function lockKey(string $slug): string
    {
        return $this->prefix().':lock:'.$slug;
    }

    public function totalClicksKey(int $linkId): string
    {
        return $this->prefix().':clicks:total:'.$linkId;
    }

    public function dailyClicksKey(int $linkId, string $date): string
    {
        return $this->prefix().':clicks:day:'.$linkId.':'.$date;
    }

    public function globalDailyClicksKey(string $date): string
    {
        return $this->prefix().':clicks:global:'.$date;
    }

    public function bufferKey(): string
    {
        return (string) config('linkforge.clicks.buffer_key', 'linkforge:clicks:buffer');
    }

    public function get(string $key): ?string
    {
        $value = $this->connection()->get($key);

        return $value === false || $value === null ? null : (string) $value;
    }

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->connection()->setex($key, max(1, $ttl), $value);
    }

    public function del(string ...$keys): void
    {
        if ($keys !== []) {
            $this->connection()->del($keys);
        }
    }

    /**
     * SET key value NX EX ttl — used as the single-flight regeneration lock.
     */
    public function acquireLock(string $key, int $ttl): bool
    {
        return (bool) $this->connection()->set($key, '1', 'EX', max(1, $ttl), 'NX');
    }

    public function releaseLock(string $key): void
    {
        $this->connection()->del([$key]);
    }

    /**
     * Atomically hand out the next slug id for the counter strategy.
     */
    public function nextSlugId(): int
    {
        return (int) $this->connection()->incr((string) config('linkforge.slug.counter_key', 'linkforge:slug:counter'));
    }

    /**
     * Run a batch of commands in one round-trip.
     *
     * @param  callable(mixed): void  $callback
     */
    public function pipeline(callable $callback): array
    {
        return (array) $this->connection()->pipeline($callback);
    }

    /**
     * Hot-path click write: append the record to the buffer and bump the live
     * counters, all in a single pipelined round-trip.
     *
     * @param  array<int, string>  $counterKeys  keys to INCR
     * @param  array<int, string>  $expiringKeys subset of $counterKeys that should carry a TTL
     */
    public function pushClick(string $payload, array $counterKeys, array $expiringKeys, int $ttl): void
    {
        $bufferKey = $this->bufferKey();

        $this->pipeline(function ($pipe) use ($payload, $bufferKey, $counterKeys, $expiringKeys, $ttl) {
            $pipe->rpush($bufferKey, [$payload]);

            foreach ($counterKeys as $key) {
                $pipe->incr($key);
            }

            foreach ($expiringKeys as $key) {
                $pipe->expire($key, $ttl);
            }
        });
    }

    /**
     * Atomically take up to $limit records off the head of the click buffer.
     *
     * LRANGE + LTRIM in one MULTI means the records this call returns are the
     * records it removed — no other worker can see them.
     *
     * @return array<int, string>
     */
    public function drainBuffer(int $limit): array
    {
        $key = $this->bufferKey();

        $results = $this->connection()->transaction(function ($tx) use ($key, $limit) {
            $tx->lrange($key, 0, $limit - 1);
            $tx->ltrim($key, $limit, -1);
        });

        $raw = $results[0] ?? [];

        return is_array($raw) ? array_map('strval', $raw) : [];
    }

    public function bufferDepth(): int
    {
        return (int) $this->connection()->llen($this->bufferKey());
    }

    /**
     * Drop the oldest $count entries from the buffer.
     */
    public function trimBuffer(int $count): void
    {
        if ($count > 0) {
            $this->connection()->ltrim($this->bufferKey(), $count, -1);
        }
    }

    /**
     * @return array<int, string>
     */
    public function mget(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return (array) $this->connection()->mget($keys);
    }

    public function counter(string $key): int
    {
        return (int) ($this->get($key) ?? 0);
    }
}
