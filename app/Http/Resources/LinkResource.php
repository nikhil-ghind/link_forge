<?php

namespace App\Http\Resources;

use App\Models\Link;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Link
 */
class LinkResource extends JsonResource
{
    /**
     * Live click count from Redis, injected by the controller so the list
     * endpoint can fetch all of them in a single MGET instead of one GET per
     * row.
     */
    public function __construct($resource, private readonly ?int $liveClicks = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'short_url' => $this->shortUrl(),
            'target_url' => $this->target_url,
            'title' => $this->title,
            'domain' => $this->domain,
            'redirect_status' => $this->redirect_status,
            'is_active' => $this->is_active,
            'is_custom_alias' => $this->is_custom_alias,
            'is_redirectable' => $this->isRedirectable(),
            'click_count' => max($this->click_count, $this->liveClicks ?? 0),
            'persisted_click_count' => $this->click_count,
            'max_clicks' => $this->max_clicks,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_clicked_at' => $this->last_clicked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
