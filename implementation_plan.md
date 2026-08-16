# LinkForge — Implementation Plan

**LinkForge** is a high-throughput URL shortener built on a LAMP stack: a
**PHP 8.2 / Laravel 11** application served by **Apache** on **AWS Linux**, with
**MySQL 8** for durable link + click storage, **Redis** for redirect-path caching
and click buffering, and a **React + TypeScript** analytics dashboard.

The design goal that shapes every phase: **the redirect hot path must never touch
MySQL on a cache hit and must never write synchronously.** A `GET /{slug}` should
be one Redis `GET` plus a `302`, with the click recorded into a Redis buffer that
a queue worker drains in batches.

---

## Architecture summary

```
client ──► Apache ──► Laravel
             │          ├─ GET /{slug}      → LinkCache (Redis) → 302   [hot path]
             │          │                     miss → MySQL → warm cache
             │          │                     unknown → negative cache sentinel
             │          │                     click → Redis LPUSH buffer  (no I/O wait)
             │          ├─ /api/links       → link CRUD (MySQL, cache invalidation)
             │          └─ /api/analytics/* → aggregates (daily rollups + live buffer)
             │
             └─ queue worker (systemd) ─ ProcessClickBuffer ─► click_events (bulk insert)
                                       └ AggregateDailyStats ─► daily_link_stats
```

---

## Phase 1 — Application skeleton, domain schema, slug encoding

Lay down the Laravel application, configuration, database schema and the
encoding primitives the shortener is built on.

**Deliverables**

- Composer manifest, `artisan` entrypoint, bootstrap/providers wiring.
- Config: `config/app.php`, `database.php` (MySQL), `cache.php` + `database.php`
  Redis stores, `queue.php` (redis connection), and a dedicated
  `config/linkforge.php` holding TTLs, buffer sizes, slug length, reserved slugs.
- `.env.example` documenting every knob.
- Migrations: `links`, `click_events`, `daily_link_stats`, `jobs`, `failed_jobs`,
  `api_tokens`.
- Eloquent models `Link`, `ClickEvent`, `DailyLinkStat`, `ApiToken` with casts,
  relationships and query scopes.
- `App\Support\Base62` — bijective base62 encode/decode with an alphabet that
  avoids ambiguous characters, plus checksum-free ID obfuscation.
- `App\Services\SlugGenerator` — counter-derived slugs, random slugs with
  collision retry, custom-alias validation against reserved words.

**Files touched**

```
composer.json  artisan  .env.example
bootstrap/app.php  bootstrap/providers.php
config/{app,database,cache,queue,logging,linkforge}.php
database/migrations/*.php
app/Models/{Link,ClickEvent,DailyLinkStat,ApiToken}.php
app/Support/Base62.php
app/Services/SlugGenerator.php
app/Providers/AppServiceProvider.php
```

---

## Phase 2 — Redirect hot path: Redis cache-aside + buffered click tracking

The performance core of the project.

**Deliverables**

- `App\Services\LinkCache` — cache-aside over Redis:
  - `resolve(slug)` returns a hydrated `ResolvedLink` value object;
  - hit → single Redis `GET`, no MySQL;
  - miss → MySQL lookup, then `SETEX` with jittered TTL to avoid stampedes;
  - **negative caching**: unknown slugs store a `MISS` sentinel with a short TTL so
    scanner traffic cannot hammer MySQL;
  - short-lived per-slug lock so a cold popular slug produces one DB read, not N.
- `App\Services\ClickBuffer` — `RPUSH` of a compact click record onto a Redis
  list plus `INCR` on live counters (`clicks:total:{id}`, `clicks:day:{id}:{date}`),
  pipelined in one round-trip; never blocks the response.
- `App\Http\Controllers\RedirectController` — resolves, honours expiry /
  max-clicks / disabled state, emits `301`/`302` per link config, adds
  cache-control headers, records the click, and renders a friendly 404 view.
- `App\Support\ClickRecord` — the wire format written to the buffer.
- `App\Console\Commands\DrainClickBuffer` + `App\Jobs\ProcessClickBatch` —
  `LRANGE`/`LTRIM` drain, parse, enrich (UA → device/browser/os, IP → country,
  referrer → host), then a single chunked bulk insert into `click_events`.
- `App\Services\UserAgentParser`, `App\Services\GeoResolver` (CloudFront/
  X-Country header aware, with a private/reserved IP fallback).
- Unit tests for cache-aside behaviour, negative caching, UA parsing.

**Files touched**

```
app/Services/{LinkCache,ClickBuffer,UserAgentParser,GeoResolver}.php
app/Support/{ResolvedLink,ClickRecord}.php
app/Http/Controllers/RedirectController.php
app/Jobs/ProcessClickBatch.php
app/Console/Commands/DrainClickBuffer.php
routes/web.php  resources/views/errors/link-not-found.blade.php
tests/Unit/{LinkCacheTest,UserAgentParserTest,Base62Test}.php
```

---

## Phase 3 — Link management API, analytics aggregation, auth

Everything the dashboard talks to.

**Deliverables**

- `LinkController` — paginated list with search/sort, create (custom alias or
  generated), show, update (with cache invalidation), soft delete, bulk create.
- Form requests with URL validation, scheme allow-list, SSRF-ish host blocking
  (loopback/link-local/private ranges), alias charset rules.
- `App\Http\Middleware\ApiTokenAuth` — hashed bearer tokens, per-token rate limit.
- `App\Services\AnalyticsService` — time series (hour/day granularity), top links,
  breakdowns by referrer host, country, device, browser; summary KPIs with
  period-over-period deltas. Reads pre-aggregated `daily_link_stats` for long
  ranges and raw `click_events` for short ranges, merging live Redis counters so
  "today" is accurate before the rollup runs.
- `App\Jobs\AggregateDailyStats` + `linkforge:rollup` command + scheduler entry.
- `AnalyticsController` with `/summary`, `/timeseries`, `/top-links`,
  `/breakdown/{dimension}`, `/links/{link}/stats`.
- API resources for consistent JSON shapes; feature tests for the API surface.

**Files touched**

```
app/Http/Controllers/{LinkController,AnalyticsController,HealthController}.php
app/Http/Requests/{StoreLinkRequest,UpdateLinkRequest,BulkStoreLinkRequest}.php
app/Http/Resources/{LinkResource,ClickEventResource}.php
app/Http/Middleware/ApiTokenAuth.php
app/Services/AnalyticsService.php
app/Jobs/AggregateDailyStats.php
app/Console/Commands/{RollupStats,IssueApiToken}.php
routes/api.php  routes/console.php
tests/Feature/{LinkApiTest,RedirectTest,AnalyticsApiTest}.php
database/factories/*.php  database/seeders/DemoSeeder.php
```

---

## Phase 4 — React analytics dashboard

A Vite + React + TypeScript SPA consuming the analytics API.

**Deliverables**

- Vite/TS/ESLint config, `index.html`, theme-aware CSS design tokens.
- Typed API client with token handling and error normalisation.
- Data hooks (`useSummary`, `useTimeseries`, `useTopLinks`, `useBreakdown`,
  `useLinks`) with polling + abort handling.
- Charts (Recharts): click time series with range selector, top-links bar chart,
  referrer/country/device/browser donut + bar breakdowns, sparklines in KPI tiles.
- Pages: Overview dashboard, Links table (create/edit/disable, copy short URL,
  QR-free but with per-link drill-down), Link detail with its own time series.
- Shared UI: stat tiles, data table with sorting/pagination, empty/loading/error
  states, relative-time and compact-number formatting utilities.

**Files touched**

```
dashboard/{package.json,vite.config.ts,tsconfig.json,index.html,.eslintrc.json}
dashboard/src/{main.tsx,App.tsx,styles.css}
dashboard/src/api/{client.ts,types.ts,endpoints.ts}
dashboard/src/hooks/*.ts
dashboard/src/components/**/*.tsx
dashboard/src/pages/{Overview,Links,LinkDetail}.tsx
dashboard/src/lib/{format.ts,palette.ts}
```

---

## Phase 5 — AWS Linux deployment, operations, docs

Make it deployable and explain it.

**Deliverables**

- `deploy/apache/linkforge.conf` — vhost for the redirect domain (document root
  `public/`, rewrite rules, `KeepAlive`, expires headers, gzip) and a second
  vhost serving the built dashboard + `/api` proxying.
- `deploy/systemd/linkforge-worker.service` — queue worker (`queue:work redis`)
  with restart policy, memory cap, `--max-time`; `linkforge-scheduler.timer`
  + `.service` for `schedule:run`.
- `deploy/bootstrap-al2023.sh` — Amazon Linux 2023 provisioning notes
  (php8.2-fpm/mod_php, composer, redis6, RDS connectivity, log rotation).
- `deploy/mysql/tuning.md`, `deploy/README.md` — capacity notes, index rationale,
  read-replica story, cache warm/invalidations, benchmark script `deploy/bench.sh`.
- Root `.gitignore` (vendor, node_modules, dist, `.env`, storage logs, IDE).
- Root `README.md` — what it is, Mermaid architecture diagram, request-path
  walkthrough, API reference, how to run (local + AWS), how to test.

**Files touched**

```
deploy/**  .gitignore  README.md
```
