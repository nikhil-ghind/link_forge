# Deploying LinkForge on AWS Linux

## Topology

```
Route 53
  ├── lnkf.rg              → ALB → EC2 (Apache: redirect vhost)      ← the hot path
  └── api.linkforge.example → ALB → EC2 (Apache: API + dashboard vhost)

EC2 (Amazon Linux 2023)
  ├── httpd + php-fpm (pool: linkforge)
  ├── linkforge-worker.service      (queue:work redis — drains clicks)
  └── linkforge-scheduler.timer     (schedule:run every minute)

ElastiCache (Redis)   db 0: queues · db 1: redirect cache + click buffer · db 2: app cache
RDS (MySQL 8)         primary + optional read replica for analytics
```

Both vhosts point at the same document root and the same PHP pool; they differ
in timeouts, keepalive, logging and what they expose. Splitting them means a
redirect flood cannot exhaust the workers the dashboard needs, and the redirect
domain never serves the management API.

## First deploy

```bash
sudo REPO=https://github.com/nikhil-ghind/link_forge.git \
     bash deploy/bootstrap-al2023.sh

cd /var/www/linkforge
sudo -u apache php artisan migrate --force
sudo -u apache php artisan linkforge:token dashboard   # copy the printed token

cd dashboard && npm ci && npm run build
```

## Subsequent deploys

```bash
cd /var/www/linkforge
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache

# Recycle the worker so it picks up the new code. The buffer is in Redis, so
# nothing in flight is lost across the restart.
sudo systemctl restart linkforge-worker

# Optional: preload the hottest links so the first traffic after a config:cache
# does not arrive as a wave of cache misses.
php artisan linkforge:warm-cache --limit=2000
```

## Operating notes

**Buffer depth is the health signal.** `GET /api/health` reports
`click_buffer_depth`. Steady state is near zero. A rising depth means the worker
is down or MySQL is slow; clicks are still being accepted and no redirect is
affected, but the dashboard's persisted numbers will lag. Alert above ~50k.

**Redis loss is survivable.** `LinkCache` catches connection failures and reads
straight from MySQL, so redirects keep working at a higher latency. What is lost
is the buffered clicks that had not yet drained.

**MySQL loss is partially survivable.** Cached slugs keep redirecting; new links
cannot be created and the drain will retry and eventually fail into
`failed_jobs`.

**Draining manually**, if the worker has been down and you want to catch up
without waiting for the scheduler:

```bash
php artisan linkforge:drain-clicks --loop
php artisan linkforge:rollup --days=3
```

## Scaling

The redirect path is stateless and its working set is a Redis `GET`, so app
nodes scale horizontally behind the ALB with no coordination. The pieces that
need attention as traffic grows, in the order they bite:

1. **Click drain throughput** — run more `linkforge-worker` instances, or raise
   `LINKFORGE_CLICK_DRAIN_BATCH`. One worker comfortably handles a few thousand
   clicks/second on the batch-insert path.
2. **`click_events` size** — partition by month and drop old partitions once the
   rollups cover them (see `mysql/tuning.md`).
3. **Redis memory** — each cached link is a few dozen bytes. Ten million links
   is a couple of GB; set `maxmemory-policy allkeys-lru` so the cold tail is
   evicted rather than the instance filling.

## Benchmarking

```bash
SLUG=abc1234 HOST=https://lnkf.rg REQUESTS=50000 CONCURRENCY=200 bash deploy/bench.sh
```

It reports warm (Redis hit), cold (MySQL fallback) and miss (negative cache)
separately, because the average across all three tells you nothing useful.
