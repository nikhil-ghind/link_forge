<?php

namespace Tests\Unit;

use App\Models\Link;
use App\Services\LinkCache;
use App\Services\RedisLinkStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * In-memory stand-in for the Redis link store so the cache-aside behaviour can
 * be asserted without a live Redis.
 */
class ArrayLinkStore extends RedisLinkStore
{
    /** @var array<string, string> */
    public array $data = [];

    public int $reads = 0;

    public int $writes = 0;

    public function __construct()
    {
        parent::__construct('links');
    }

    public function get(string $key): ?string
    {
        $this->reads++;

        return $this->data[$key] ?? null;
    }

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->writes++;
        $this->data[$key] = $value;
    }

    public function del(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($this->data[$key]);
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
}

class LinkCacheTest extends TestCase
{
    use RefreshDatabase;

    private ArrayLinkStore $store;

    private LinkCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ArrayLinkStore;
        $this->cache = new LinkCache($this->store);
    }

    private function makeLink(array $attributes = []): Link
    {
        return Link::create(array_merge([
            'slug' => 'abc1234',
            'target_url' => 'https://example.com/landing',
            'target_hash' => Link::hashTarget('https://example.com/landing'),
            'domain' => 'example.com',
            'redirect_status' => 302,
            'is_active' => true,
        ], $attributes));
    }

    public function test_a_miss_reads_the_database_and_populates_the_cache(): void
    {
        $link = $this->makeLink();

        $resolved = $this->cache->resolve('abc1234');

        $this->assertNotNull($resolved);
        $this->assertSame($link->id, $resolved->id);
        $this->assertSame('https://example.com/landing', $resolved->targetUrl);
        $this->assertArrayHasKey($this->store->slugKey('abc1234'), $this->store->data);
    }

    public function test_a_hit_never_touches_the_database(): void
    {
        $this->makeLink();
        $this->cache->resolve('abc1234');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $resolved = $this->cache->resolve('abc1234');

        $this->assertNotNull($resolved);
        $this->assertSame(0, $queries, 'a cache hit must not issue any SQL');
    }

    public function test_unknown_slugs_are_negatively_cached(): void
    {
        $this->assertNull($this->cache->resolve('nothere'));

        $sentinel = (string) config('linkforge.cache.miss_sentinel');
        $this->assertSame($sentinel, $this->store->data[$this->store->slugKey('nothere')] ?? null);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        // Second lookup of the same unknown slug is served entirely by Redis:
        // this is what keeps slug-space scanners off MySQL.
        $this->assertNull($this->cache->resolve('nothere'));
        $this->assertSame(0, $queries);
    }

    public function test_forget_evicts_the_entry_so_the_next_read_reloads(): void
    {
        $link = $this->makeLink();
        $this->cache->resolve('abc1234');

        $link->update(['target_url' => 'https://example.com/updated']);
        $this->cache->forget('abc1234');

        $resolved = $this->cache->resolve('abc1234');

        $this->assertSame('https://example.com/updated', $resolved?->targetUrl);
    }

    public function test_inactive_and_expired_state_survives_the_round_trip(): void
    {
        $this->makeLink([
            'slug' => 'expired1',
            'is_active' => false,
            'expires_at' => now()->subDay(),
            'max_clicks' => 5,
        ]);

        $resolved = $this->cache->resolve('expired1');

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->isActive);
        $this->assertTrue($resolved->isExpired());
        $this->assertTrue($resolved->hasReachedCap(5));
        $this->assertFalse($resolved->hasReachedCap(4));
    }

    public function test_warming_preloads_the_hottest_links(): void
    {
        $this->makeLink(['slug' => 'hot0001']);
        $this->makeLink(['slug' => 'hot0002']);

        $warmed = $this->cache->warm(10);

        $this->assertSame(2, $warmed);
        $this->assertArrayHasKey($this->store->slugKey('hot0001'), $this->store->data);
        $this->assertArrayHasKey($this->store->slugKey('hot0002'), $this->store->data);
    }
}
