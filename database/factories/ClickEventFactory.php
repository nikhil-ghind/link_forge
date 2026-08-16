<?php

namespace Database\Factories;

use App\Models\ClickEvent;
use App\Models\Link;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClickEvent>
 */
class ClickEventFactory extends Factory
{
    protected $model = ClickEvent::class;

    public function definition(): array
    {
        $referrers = ['direct', 'google.com', 't.co', 'linkedin.com', 'news.ycombinator.com', 'reddit.com'];
        $devices = ['desktop', 'mobile', 'tablet'];
        $browsers = ['Chrome', 'Safari', 'Firefox', 'Edge'];
        $systems = ['Windows', 'macOS', 'iOS', 'Android', 'Linux'];

        return [
            'link_id' => Link::factory(),
            'clicked_at' => $this->faker->dateTimeBetween('-30 days'),
            'referrer_host' => $this->faker->randomElement($referrers),
            'referrer_url' => null,
            'country' => $this->faker->randomElement(['US', 'GB', 'DE', 'IN', 'BR', 'JP', 'CA']),
            'device_type' => $this->faker->randomElement($devices),
            'browser' => $this->faker->randomElement($browsers),
            'os' => $this->faker->randomElement($systems),
            'user_agent' => $this->faker->userAgent(),
            'visitor_hash' => substr(hash('sha256', (string) $this->faker->unique()->randomNumber(8)), 0, 32),
            'is_bot' => false,
        ];
    }

    public function bot(): static
    {
        return $this->state(fn () => ['is_bot' => true, 'device_type' => 'bot', 'browser' => null]);
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['clicked_at' => $date]);
    }
}
