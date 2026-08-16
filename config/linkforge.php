<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Short domain
    |--------------------------------------------------------------------------
    |
    | The host short links are rendered against. Usually a dedicated short
    | domain served by its own Apache vhost so the redirect path can be tuned
    | independently from the API/dashboard vhost.
    |
    */

    'short_domain' => env('LINKFORGE_SHORT_DOMAIN', 'http://localhost:8000'),

    /*
    |--------------------------------------------------------------------------
    | Slug generation
    |--------------------------------------------------------------------------
    |
    | "counter" derives slugs from a monotonically increasing Redis counter
    | encoded in base62 (dense, no collisions, sequential-ish). "random" draws
    | random base62 strings and retries on collision (unguessable).
    |
    */

    'slug' => [
        'strategy' => env('LINKFORGE_SLUG_STRATEGY', 'random'),
        'length' => (int) env('LINKFORGE_SLUG_LENGTH', 7),
        'min_length' => 4,
        'max_length' => 32,
        'max_attempts' => 6,
        'counter_key' => 'linkforge:slug:counter',
        'counter_offset' => (int) env('LINKFORGE_SLUG_COUNTER_OFFSET', 100_000_000),
        'reserved' => [
            'api', 'admin', 'dashboard', 'login', 'logout', 'register', 'up',
            'health', 'status', 'metrics', 'assets', 'static', 'favicon.ico',
            'robots.txt', 'sitemap.xml', 'about', 'terms', 'privacy', 'docs',
            'support', 'help', 'app', 'www', 'link', 'links', 'stats', 's',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect cache (cache-aside)
    |--------------------------------------------------------------------------
    |
    | ttl        : base lifetime of a positive cache entry, in seconds.
    | ttl_jitter : random seconds added to the TTL so a burst of entries
    |              written together does not expire together (stampede guard).
    | negative_ttl : lifetime of the "this slug does not exist" sentinel. Keeps
    |              scanner/404 traffic from reaching MySQL at all.
    | lock_ttl   : per-slug regeneration lock so exactly one request rebuilds a
    |              cold entry; the rest do a short spin then re-read the cache.
    |
    */

    'cache' => [
        'prefix' => env('LINKFORGE_CACHE_PREFIX', 'lf'),
        'ttl' => (int) env('LINKFORGE_CACHE_TTL', 86_400),
        'ttl_jitter' => (int) env('LINKFORGE_CACHE_TTL_JITTER', 3_600),
        'negative_ttl' => (int) env('LINKFORGE_CACHE_NEGATIVE_TTL', 60),
        'lock_ttl' => 5,
        'lock_wait_ms' => 40,
        'lock_retries' => 4,
        'miss_sentinel' => '\0MISS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Click buffering
    |--------------------------------------------------------------------------
    |
    | Clicks are RPUSHed onto a Redis list on the hot path and drained in bulk
    | by the queue worker. `drain_batch` caps how many entries one drain pass
    | pulls; `max_buffer` is the safety cap after which the oldest entries are
    | trimmed away rather than letting Redis grow unbounded.
    |
    */

    'clicks' => [
        'buffer_key' => 'linkforge:clicks:buffer',
        'drain_batch' => (int) env('LINKFORGE_CLICK_DRAIN_BATCH', 2_000),
        'insert_chunk' => 500,
        'max_buffer' => (int) env('LINKFORGE_CLICK_MAX_BUFFER', 500_000),
        'drain_interval_seconds' => (int) env('LINKFORGE_CLICK_DRAIN_INTERVAL', 5),
        'counter_ttl' => 172_800,
        'store_ip' => env('LINKFORGE_STORE_IP', false),
        'ip_hash_salt' => env('LINKFORGE_IP_HASH_SALT', 'change-me'),
        'queue' => env('LINKFORGE_CLICK_QUEUE', 'clicks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    |
    | 302 by default: a 301 is cached by browsers forever, which silently kills
    | click tracking and makes a link impossible to re-target.
    |
    */

    'redirect' => [
        'status' => (int) env('LINKFORGE_REDIRECT_STATUS', 302),
        'allowed_schemes' => ['http', 'https'],
        'blocked_hosts' => [
            'localhost', '127.0.0.1', '0.0.0.0', '::1', 'metadata.google.internal',
            '169.254.169.254',
        ],
        'max_target_length' => 2_048,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics
    |--------------------------------------------------------------------------
    |
    | Ranges longer than `rollup_threshold_days` are served from the
    | daily_link_stats rollup table; shorter ranges scan click_events directly
    | so the dashboard stays accurate at hour granularity.
    |
    */

    'analytics' => [
        'rollup_threshold_days' => 14,
        'max_range_days' => 400,
        'default_range_days' => 30,
        'top_limit' => 10,
        'summary_cache_ttl' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    'api' => [
        'rate_limit_per_minute' => (int) env('LINKFORGE_API_RATE_LIMIT', 120),
        'max_bulk_links' => 100,
    ],
];
