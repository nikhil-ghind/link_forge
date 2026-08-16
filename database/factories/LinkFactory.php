<?php

namespace Database\Factories;

use App\Models\Link;
use App\Support\Base62;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Link>
 */
class LinkFactory extends Factory
{
    protected $model = Link::class;

    public function definition(): array
    {
        $target = $this->faker->url();

        return [
            'slug' => Base62::random(7),
            'target_url' => $target,
            'target_hash' => Link::hashTarget($target),
            'title' => $this->faker->sentence(4),
            'domain' => parse_url($target, PHP_URL_HOST),
            'redirect_status' => 302,
            'is_active' => true,
            'is_custom_alias' => false,
            'click_count' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function capped(int $maxClicks = 5): static
    {
        return $this->state(fn () => ['max_clicks' => $maxClicks]);
    }

    public function withSlug(string $slug): static
    {
        return $this->state(fn () => ['slug' => $slug]);
    }
}
