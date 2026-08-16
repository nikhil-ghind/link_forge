<?php

namespace App\Support;

use App\Models\Link;
use Illuminate\Support\Carbon;

/**
 * The minimal projection of a link needed to serve a redirect.
 *
 * Deliberately not an Eloquent model: this is what gets serialised into Redis,
 * so it must stay small (a few dozen bytes) and cheap to hydrate. Anything the
 * redirect does not need belongs in MySQL only.
 */
final class ResolvedLink
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $targetUrl,
        public readonly int $redirectStatus = 302,
        public readonly bool $isActive = true,
        public readonly ?int $expiresAt = null,
        public readonly ?int $maxClicks = null,
    ) {}

    public static function fromModel(Link $link): self
    {
        return new self(
            id: $link->id,
            slug: $link->slug,
            targetUrl: $link->target_url,
            redirectStatus: $link->redirect_status,
            isActive: $link->is_active,
            expiresAt: $link->expires_at?->getTimestamp(),
            maxClicks: $link->max_clicks,
        );
    }

    /**
     * Compact positional array — roughly half the bytes of a keyed payload,
     * which matters when millions of these live in Redis.
     *
     * @param  array<int, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        if (count($payload) < 7) {
            return null;
        }

        [$id, $slug, $target, $status, $active, $expires, $maxClicks] = $payload;

        return new self(
            id: (int) $id,
            slug: (string) $slug,
            targetUrl: (string) $target,
            redirectStatus: (int) $status,
            isActive: (bool) $active,
            expiresAt: $expires === null ? null : (int) $expires,
            maxClicks: $maxClicks === null ? null : (int) $maxClicks,
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function toArray(): array
    {
        return [
            $this->id,
            $this->slug,
            $this->targetUrl,
            $this->redirectStatus,
            $this->isActive ? 1 : 0,
            $this->expiresAt,
            $this->maxClicks,
        ];
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= Carbon::now()->getTimestamp();
    }

    /**
     * Evaluated against the live Redis click counter so a capped link stops
     * redirecting immediately rather than after the next MySQL drain.
     */
    public function hasReachedCap(int $liveClicks): bool
    {
        return $this->maxClicks !== null && $liveClicks >= $this->maxClicks;
    }
}
