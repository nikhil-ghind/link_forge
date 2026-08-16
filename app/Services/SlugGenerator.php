<?php

namespace App\Services;

use App\Models\Link;
use App\Support\Base62;
use RuntimeException;

class SlugGenerator
{
    public function __construct(private readonly RedisLinkStore $store) {}

    /**
     * Produce a slug that is not currently taken.
     *
     * The "counter" strategy INCRs a Redis counter and base62-encodes it: no
     * database round-trip, no collisions by construction, dense slug space.
     * The "random" strategy draws from the unambiguous alphabet and verifies
     * against MySQL, retrying on the (rare) collision.
     */
    public function generate(?string $strategy = null): string
    {
        $strategy ??= (string) config('linkforge.slug.strategy', 'random');

        return match ($strategy) {
            'counter' => $this->fromCounter(),
            'random' => $this->random(),
            default => throw new RuntimeException("Unknown slug strategy [{$strategy}]."),
        };
    }

    private function fromCounter(): string
    {
        $length = (int) config('linkforge.slug.length', 7);
        $offset = (int) config('linkforge.slug.counter_offset', 100_000_000);

        $attempts = (int) config('linkforge.slug.max_attempts', 6);

        for ($i = 0; $i < $attempts; $i++) {
            $id = $offset + $this->store->nextSlugId();
            $slug = Base62::encodePadded($id, $length);

            // A custom alias could have squatted this exact string, so the
            // counter is authoritative but not blindly trusted.
            if (! $this->isReserved($slug) && ! $this->exists($slug)) {
                return $slug;
            }
        }

        throw new RuntimeException('Exhausted slug generation attempts from the counter strategy.');
    }

    private function random(): string
    {
        $length = (int) config('linkforge.slug.length', 7);
        $attempts = (int) config('linkforge.slug.max_attempts', 6);

        for ($i = 0; $i < $attempts; $i++) {
            // Widen the slug after a few collisions rather than spinning at a
            // saturated length forever.
            $candidate = Base62::random($length + intdiv($i, 2));

            if (! $this->isReserved($candidate) && ! $this->exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Exhausted slug generation attempts from the random strategy.');
    }

    /**
     * Validate a user-supplied alias. Returns an error message, or null when
     * the alias is acceptable.
     */
    public function validateAlias(string $alias): ?string
    {
        $min = (int) config('linkforge.slug.min_length', 4);
        $max = (int) config('linkforge.slug.max_length', 32);
        $length = strlen($alias);

        if ($length < $min || $length > $max) {
            return "Alias must be between {$min} and {$max} characters.";
        }

        if (! Base62::isValid($alias)) {
            return 'Alias may only contain letters and digits (a-z, A-Z, 0-9).';
        }

        if ($this->isReserved($alias)) {
            return 'That alias is reserved.';
        }

        if ($this->exists($alias)) {
            return 'That alias is already in use.';
        }

        return null;
    }

    public function isReserved(string $slug): bool
    {
        $reserved = (array) config('linkforge.slug.reserved', []);

        return in_array(strtolower($slug), array_map('strtolower', $reserved), true);
    }

    /**
     * Includes soft-deleted links: a deleted slug must not be handed out again
     * or old QR codes/printed links would start pointing somewhere new.
     */
    public function exists(string $slug): bool
    {
        return Link::withTrashed()->where('slug', $slug)->exists();
    }
}
