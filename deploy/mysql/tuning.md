# MySQL notes

## Why the redirect path barely touches MySQL

On a cache hit the redirect is a single Redis `GET`. MySQL is only read when:

1. a slug is not in the cache (first hit after a TTL expiry, a deploy, or an
   ElastiCache failover), or
2. Redis is unreachable, in which case `LinkCache` falls back to a direct read
   rather than failing the request.

That makes the `links` table effectively a cold-start store. It is sized for
writes and point lookups, not for scans.

## Index rationale

| Table | Index | Serves |
|---|---|---|
| `links` | `unique(slug)` (`ascii_bin`) | the only lookup on the redirect path. `ascii_bin` gives case-sensitive exact match and keeps the key ~1 byte/char |
| `links` | `(is_active, created_at)` | the dashboard's default list ordering |
| `links` | `click_count` | "top links" fallback and cache warming |
| `links` | `target_hash` | duplicate detection without indexing a 2 KB URL column |
| `click_events` | `(link_id, clicked_at)` | per-link analytics — the leading column is the filter, the second is the range |
| `click_events` | `clicked_at` | fleet-wide time-range aggregates |
| `daily_link_stats` | `unique(link_id, stat_date)` | makes the rollup an idempotent upsert |

`click_events` has **no index on `referrer_host`, `country` or `device_type`**
on purpose: those columns are only ever read inside an already-narrow time
window, and indexing them would add four secondary index writes to the hot
insert path for no read benefit.

## Write path

Clicks are inserted in batches of 500 by the drain worker, not one row per
redirect. At 5,000 clicks/second that is ten multi-row inserts per second
instead of five thousand single-row transactions — the difference between an
`db.t4g.medium` coping and not.

Because inserts are append-only on an auto-increment primary key, they land at
the right edge of the clustered index with no page splits.

## Parameter starting points (RDS parameter group)

| Parameter | Value | Why |
|---|---|---|
| `innodb_buffer_pool_size` | ~70% of instance RAM | the whole `links` table should live in memory |
| `innodb_flush_log_at_trx_commit` | `2` | click telemetry does not justify an fsync per commit; the Redis counters are the authority for live totals anyway |
| `innodb_log_file_size` | 1 GB | the batch inserts are write-heavy and bursty |
| `max_connections` | 200 | php-fpm `pm.max_children` (48) × app nodes, plus workers and headroom |
| `long_query_time` | `0.5` | anything slower than this on this schema is a missing index |

## Read replica

`config/database.php` accepts `DB_READ_HOST` as a comma-separated replica list.
Analytics scans over `click_events` are the only queries heavy enough to matter,
and pointing them at a replica keeps them away from the primary that cache
misses fall back to. `sticky` is enabled so a request that just created a link
reads its own write.

## Retention

`click_events` grows without bound. Once `daily_link_stats` covers a period, the
raw rows for it are only needed for hour-level drill-down. In production this
would be partitioned by month:

```sql
ALTER TABLE click_events
PARTITION BY RANGE (TO_DAYS(clicked_at)) (
    PARTITION p2026_07 VALUES LESS THAN (TO_DAYS('2026-08-01')),
    PARTITION p2026_08 VALUES LESS THAN (TO_DAYS('2026-09-01')),
    PARTITION pmax     VALUES LESS THAN MAXVALUE
);
```

Dropping a partition is instant; `DELETE FROM click_events WHERE clicked_at < …`
on a table of this size is not.
