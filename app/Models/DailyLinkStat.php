<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyLinkStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'link_id',
        'stat_date',
        'clicks',
        'unique_visitors',
        'bot_clicks',
        'referrers',
        'countries',
        'devices',
        'browsers',
    ];

    protected $casts = [
        'link_id' => 'integer',
        'stat_date' => 'date',
        'clicks' => 'integer',
        'unique_visitors' => 'integer',
        'bot_clicks' => 'integer',
        'referrers' => 'array',
        'countries' => 'array',
        'devices' => 'array',
        'browsers' => 'array',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    public function scopeBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('stat_date', [$from, $to]);
    }
}
