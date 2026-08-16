<?php

namespace Database\Seeders;

use App\Jobs\AggregateDailyStats;
use App\Models\ApiToken;
use App\Models\ClickEvent;
use App\Models\Link;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Generates a plausible 45-day traffic history so the dashboard has something
 * to draw against on a fresh install.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        [$token, $plaintext] = ApiToken::issue('demo-dashboard');

        $this->command?->info("Demo API token (#{$token->id}): {$plaintext}");

        $links = Link::factory()
            ->count(12)
            ->sequence(fn ($sequence) => ['title' => 'Campaign '.($sequence->index + 1)])
            ->create();

        $days = 45;

        foreach ($links as $index => $link) {
            // A few links carry most of the traffic — a flat distribution makes
            // the "top links" chart useless for eyeballing the UI.
            $weight = max(1, 40 - ($index * 3));

            for ($dayOffset = $days; $dayOffset >= 0; $dayOffset--) {
                $date = Carbon::now('UTC')->subDays($dayOffset);

                // Weekday-heavy traffic with a slow upward trend.
                $base = $date->isWeekend() ? intdiv($weight, 2) : $weight;
                $trend = (int) round($base * (1 + (($days - $dayOffset) / $days) * 0.6));
                $clicks = max(0, $trend + random_int(-3, 5));

                if ($clicks === 0) {
                    continue;
                }

                ClickEvent::factory()
                    ->count($clicks)
                    ->for($link)
                    ->state(fn () => [
                        'clicked_at' => $date->copy()->setTime(random_int(0, 23), random_int(0, 59)),
                    ])
                    ->create();
            }

            $link->update([
                'click_count' => $link->clickEvents()->count(),
                'last_clicked_at' => $link->clickEvents()->max('clicked_at'),
            ]);
        }

        (new AggregateDailyStats($days + 1))->handle();

        $this->command?->info('Seeded '.$links->count().' links with '.ClickEvent::count().' clicks.');
    }
}
