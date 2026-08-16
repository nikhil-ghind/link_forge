<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only click fact table. Rows are never updated, so Eloquent timestamps
 * are disabled in favour of the explicit `clicked_at` written by the drain.
 */
class ClickEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'link_id',
        'clicked_at',
        'referrer_host',
        'referrer_url',
        'country',
        'device_type',
        'browser',
        'os',
        'user_agent',
        'ip_address',
        'visitor_hash',
        'is_bot',
    ];

    protected $casts = [
        'link_id' => 'integer',
        'clicked_at' => 'datetime',
        'is_bot' => 'boolean',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    public function scopeBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->where('clicked_at', '>=', $from)->where('clicked_at', '<', $to);
    }

    public function scopeHumans(Builder $query): Builder
    {
        return $query->where('is_bot', false);
    }
}
