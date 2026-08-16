<?php

namespace Tests\Feature;

use App\Jobs\AggregateDailyStats;
use App\Models\ApiToken;
use App\Models\ClickEvent;
use App\Models\DailyLinkStat;
use App\Models\Link;
use App\Services\RedisLinkStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeLinkStore;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'lf_analyticstoken00000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(RedisLinkStore::class, new FakeLinkStore);

        ApiToken::factory()->withPlaintext($this->token)->create(['name' => 'tests']);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_summary_reports_totals_and_period_over_period_delta(): void
    {
        $link = Link::factory()->create();

        // 10 clicks in the current 7-day window, 5 in the one before it.
        ClickEvent::factory()->count(10)->for($link)->create(['clicked_at' => Carbon::now('UTC')->subDays(2)]);
        ClickEvent::factory()->count(5)->for($link)->create(['clicked_at' => Carbon::now('UTC')->subDays(10)]);

        $response = $this->getJson('/api/analytics/summary?days=7', $this->auth())->assertStatus(200);

        $this->assertSame(10, $response->json('data.clicks'));
        $this->assertSame(5, $response->json('data.previous_clicks'));
        $this->assertSame(100.0, $response->json('data.clicks_delta_pct'));
        $this->assertSame(1, $response->json('data.total_links'));
    }

    public function test_timeseries_zero_fills_days_without_clicks(): void
    {
        $link = Link::factory()->create();
        ClickEvent::factory()->count(3)->for($link)->create(['clicked_at' => Carbon::now('UTC')->subDays(3)->setTime(12, 0)]);

        $response = $this->getJson('/api/analytics/timeseries?days=7', $this->auth())->assertStatus(200);

        $series = $response->json('data');

        $this->assertCount(7, $series);
        $this->assertSame(3, collect($series)->sum('clicks'));

        // Every bucket exists even where there was no traffic, so the chart
        // draws a continuous line rather than collapsing empty days.
        foreach ($series as $point) {
            $this->assertArrayHasKey('bucket', $point);
            $this->assertIsInt($point['clicks']);
        }
    }

    public function test_timeseries_supports_hourly_granularity(): void
    {
        $link = Link::factory()->create();
        ClickEvent::factory()->count(2)->for($link)->create(['clicked_at' => Carbon::now('UTC')->subHours(3)]);

        $series = $this->getJson('/api/analytics/timeseries?days=1&granularity=hour', $this->auth())
            ->assertStatus(200)
            ->json('data');

        $this->assertGreaterThan(20, count($series));
        $this->assertSame(2, collect($series)->sum('clicks'));
    }

    public function test_top_links_are_ranked_by_clicks(): void
    {
        $quiet = Link::factory()->withSlug('quiet01')->create();
        $loud = Link::factory()->withSlug('loud001')->create();

        ClickEvent::factory()->count(2)->for($quiet)->create(['clicked_at' => Carbon::now('UTC')->subDay()]);
        ClickEvent::factory()->count(9)->for($loud)->create(['clicked_at' => Carbon::now('UTC')->subDay()]);

        $data = $this->getJson('/api/analytics/top-links?days=7', $this->auth())
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('loud001', $data[0]['slug']);
        $this->assertSame(9, $data[0]['clicks']);
        $this->assertStringContainsString('/loud001', $data[0]['short_url']);
    }

    public function test_breakdowns_return_labels_with_shares(): void
    {
        $link = Link::factory()->create();

        ClickEvent::factory()->count(6)->for($link)->create([
            'clicked_at' => Carbon::now('UTC')->subDay(),
            'referrer_host' => 'google.com',
            'device_type' => 'mobile',
            'country' => 'US',
        ]);
        ClickEvent::factory()->count(2)->for($link)->create([
            'clicked_at' => Carbon::now('UTC')->subDay(),
            'referrer_host' => 'direct',
            'device_type' => 'desktop',
            'country' => 'DE',
        ]);

        $referrers = $this->getJson('/api/analytics/breakdown/referrer?days=7', $this->auth())
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('google.com', $referrers[0]['label']);
        $this->assertSame(6, $referrers[0]['clicks']);
        $this->assertSame(75.0, $referrers[0]['share']);

        $devices = $this->getJson('/api/analytics/breakdown/device?days=7', $this->auth())->json('data');
        $this->assertSame('mobile', $devices[0]['label']);

        $this->getJson('/api/analytics/breakdown/nonsense?days=7', $this->auth())->assertStatus(422);
    }

    public function test_per_link_stats_bundle_everything_the_detail_page_needs(): void
    {
        $link = Link::factory()->create();
        ClickEvent::factory()->count(4)->for($link)->create(['clicked_at' => Carbon::now('UTC')->subDay()]);

        $this->getJson("/api/analytics/links/{$link->id}?days=7", $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('data.link_id', $link->id)
            ->assertJsonPath('data.summary.clicks', 4)
            ->assertJsonStructure(['data' => ['timeseries', 'referrers', 'countries', 'devices', 'browsers']]);
    }

    public function test_rollups_are_idempotent(): void
    {
        $link = Link::factory()->create();
        $date = Carbon::now('UTC')->subDay();

        ClickEvent::factory()->count(7)->for($link)->create(['clicked_at' => $date->copy()->setTime(9, 30)]);
        ClickEvent::factory()->count(2)->for($link)->bot()->create(['clicked_at' => $date->copy()->setTime(10, 0)]);

        $job = new AggregateDailyStats(2);
        $job->handle();
        $job->handle();

        $stat = DailyLinkStat::where('link_id', $link->id)->firstOrFail();

        $this->assertSame(1, DailyLinkStat::count(), 'a re-run must upsert, not duplicate');
        $this->assertSame(9, $stat->clicks);
        $this->assertSame(2, $stat->bot_clicks);
        $this->assertNotEmpty($stat->referrers);
    }

    public function test_long_ranges_are_served_from_the_rollup_table(): void
    {
        $link = Link::factory()->create();

        // A rollup row with no matching click_events rows: if the long-range
        // query answers correctly, it can only have read the rollups.
        DailyLinkStat::create([
            'link_id' => $link->id,
            'stat_date' => Carbon::now('UTC')->subDays(40)->toDateString(),
            'clicks' => 250,
            'unique_visitors' => 180,
            'bot_clicks' => 4,
        ]);

        $this->assertSame(0, ClickEvent::count());

        $summary = $this->getJson('/api/analytics/summary?days=90', $this->auth())
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(250, $summary['clicks']);
    }
}
