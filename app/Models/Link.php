<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $slug
 * @property string $target_url
 * @property string $target_hash
 * @property string|null $title
 * @property string|null $domain
 * @property int $redirect_status
 * @property bool $is_active
 * @property bool $is_custom_alias
 * @property int|null $max_clicks
 * @property int $click_count
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_clicked_at
 */
class Link extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'target_url',
        'target_hash',
        'title',
        'domain',
        'redirect_status',
        'is_active',
        'is_custom_alias',
        'max_clicks',
        'expires_at',
        'created_by',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_custom_alias' => 'boolean',
        'redirect_status' => 'integer',
        'max_clicks' => 'integer',
        'click_count' => 'integer',
        'expires_at' => 'datetime',
        'last_clicked_at' => 'datetime',
        'meta' => 'array',
    ];

    public function clickEvents(): HasMany
    {
        return $this->hasMany(ClickEvent::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyLinkStat::class);
    }

    /**
     * Canonical short URL for this link.
     */
    public function shortUrl(): string
    {
        return rtrim((string) config('linkforge.short_domain'), '/').'/'.$this->slug;
    }

    /**
     * A link is redirectable when it is enabled, unexpired and under its click
     * cap. The same predicate is evaluated against the cached payload on the
     * hot path, so keep the two in sync.
     */
    public function isRedirectable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_clicks !== null && $this->click_count >= $this->max_clicks) {
            return false;
        }

        return true;
    }

    public static function hashTarget(string $url): string
    {
        return hash('sha256', trim($url));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $inner->where('slug', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('domain', 'like', $like)
                ->orWhere('target_url', 'like', $like);
        });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }
}
