<?php

namespace Tests\Support;

use App\Services\RedisLinkStore;

/**
 * In-memory replacement for the Redis link store.
 *
 * Because every hot-path Redis call is funnelled through RedisLinkStore, the
 * whole redirect + click pipeline can be exercised end to end without a Redis
 * server by binding this in the container.
 */
class FakeLinkStore extends RedisLinkStore
{
    /** @var array<string, string> */
    public array $data = [];

    /** @var array<int, string> */
    public array $buffer = [];

    /** @var array<string, int> */
    public array $counters = [];

    public int $slugCounter = 0;

    public function __construct()
    {
        parent::__construct('links');
    }

    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->counters)) {
            return (string) $this->counters[$key];
        }

        return $this->data[$key] ?? null;
    }

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->data[$key] = $value;
    }

    public function del(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($this->data[$key], $this->counters[$key]);
        }
    }

    public function acquireLock(string $key, int $ttl): bool
    {
        if (isset($this->data[$key])) {
            return false;
        }

        $this->data[$key] = '1';

        return true;
    }

    public function releaseLock(string $key): void
    {
        unset($this->data[$key]);
    }

    public function nextSlugId(): int
    {
        return ++$this->slugCounter;
    }

    public function pushClick(string $payload, array $counterKeys, array $expiringKeys, int $ttl): void
    {
        $this->buffer[] = $payload;

        foreach ($counterKeys as $key) {
            $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
        }
    }

    public function drainBuffer(int $limit): array
    {
        $taken = array_slice($this->buffer, 0, $limit);
        $this->buffer = array_slice($this->buffer, $limit);

        return $taken;
    }

    public function bufferDepth(): int
    {
        return count($this->buffer);
    }

    public function trimBuffer(int $count): void
    {
        $this->buffer = array_slice($this->buffer, $count);
    }

    public function mget(array $keys): array
    {
        return array_map(fn (string $key) => $this->get($key), $keys);
    }

    public function counter(string $key): int
    {
        return (int) ($this->counters[$key] ?? 0);
    }
}
