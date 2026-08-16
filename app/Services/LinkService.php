<?php

namespace App\Services;

use App\Models\Link;
use App\Support\ResolvedLink;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Write-side operations on links. Every mutation is responsible for keeping the
 * Redis redirect cache coherent, which is why nothing writes to the `links`
 * table directly from a controller.
 */
class LinkService
{
    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly LinkCache $cache,
        private readonly TargetUrlValidator $urls,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Link
    {
        $target = trim((string) $data['target_url']);
        $alias = Arr::get($data, 'alias');
        $isCustom = is_string($alias) && $alias !== '';

        $attempts = (int) config('linkforge.slug.max_attempts', 6);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $slug = $isCustom ? $alias : $this->slugs->generate();

            try {
                $link = Link::create([
                    'slug' => $slug,
                    'target_url' => $target,
                    'target_hash' => Link::hashTarget($target),
                    'title' => Arr::get($data, 'title'),
                    'domain' => $this->urls->host($target),
                    'redirect_status' => (int) (Arr::get($data, 'redirect_status') ?? config('linkforge.redirect.status', 302)),
                    'is_active' => (bool) (Arr::get($data, 'is_active') ?? true),
                    'is_custom_alias' => $isCustom,
                    'max_clicks' => Arr::get($data, 'max_clicks'),
                    'expires_at' => $this->toDate(Arr::get($data, 'expires_at')),
                    'created_by' => Arr::get($data, 'created_by'),
                    'meta' => Arr::get($data, 'meta'),
                ]);
            } catch (QueryException $e) {
                // Another writer took this slug between the availability check
                // and the insert. For a generated slug, try again; a custom
                // alias has no alternative to fall back to.
                if ($isCustom || ! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                continue;
            }

            // Overwrite any negative-cache sentinel left behind by traffic that
            // probed this slug before it existed.
            $this->cache->put(ResolvedLink::fromModel($link));

            return $link;
        }

        throw new \RuntimeException('Could not allocate a unique slug after several attempts.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Link $link, array $data): Link
    {
        if (array_key_exists('target_url', $data)) {
            $target = trim((string) $data['target_url']);
            $data['target_hash'] = Link::hashTarget($target);
            $data['domain'] = $this->urls->host($target);
            $data['target_url'] = $target;
        }

        if (array_key_exists('expires_at', $data)) {
            $data['expires_at'] = $this->toDate($data['expires_at']);
        }

        $link->fill(Arr::only($data, [
            'target_url', 'target_hash', 'domain', 'title', 'redirect_status',
            'is_active', 'max_clicks', 'expires_at', 'meta',
        ]));

        $link->save();

        // Write-through rather than plain invalidation: the next redirect
        // should not have to pay for a MySQL read just because we edited it.
        $this->cache->put(ResolvedLink::fromModel($link));

        return $link;
    }

    public function delete(Link $link): void
    {
        $link->delete();

        // Evict, then let the next hit negative-cache the slug. Deleted slugs
        // are never reissued, so this cannot resurrect the old target.
        $this->cache->forget($link->slug);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, Link>
     */
    public function createMany(array $payloads): array
    {
        return array_map(fn (array $payload) => $this->create($payload), $payloads);
    }

    private function toDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // MySQL 1062 / SQLSTATE 23000, SQLite reports 23000 too.
        return (string) $e->getCode() === '23000';
    }
}
