<?php

namespace Tests\Feature;

use App\Models\ClickEvent;
use App\Models\Link;
use App\Services\ClickBuffer;
use App\Services\GeoResolver;
use App\Services\RedisLinkStore;
use App\Services\UserAgentParser;
use App\Jobs\ProcessClickBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLinkStore;
use Tests\TestCase;

class RedirectTest extends TestCase
{
    use RefreshDatabase;

    private FakeLinkStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeLinkStore;
        $this->app->instance(RedisLinkStore::class, $this->store);
    }

    public function test_a_known_slug_redirects_with_a_302(): void
    {
        $link = Link::factory()->withSlug('go1234x')->create([
            'target_url' => 'https://example.com/pricing',
        ]);

        $this->get('/go1234x')
            ->assertStatus(302)
            ->assertRedirect('https://example.com/pricing')
            ->assertHeader('X-LinkForge-Slug', 'go1234x');

        $this->assertSame(1, app(ClickBuffer::class)->liveClicks($link->id));
    }

    public function test_the_redirect_is_not_cacheable_when_it_is_temporary(): void
    {
        Link::factory()->withSlug('temp001')->create();

        $response = $this->get('/temp001');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_permanent_redirect_is_allowed_to_be_cached(): void
    {
        Link::factory()->withSlug('perm001')->create(['redirect_status' => 301]);

        $this->get('/perm001')
            ->assertStatus(301)
            ->assertHeader('Cache-Control', 'max-age=86400, public');
    }

    public function test_an_unknown_slug_returns_404_and_is_negatively_cached(): void
    {
        $this->get('/missing')->assertStatus(404);

        $this->assertSame(
            (string) config('linkforge.cache.miss_sentinel'),
            $this->store->data[$this->store->slugKey('missing')] ?? null
        );
    }

    public function test_disabled_expired_and_capped_links_return_410(): void
    {
        Link::factory()->withSlug('off0001')->disabled()->create();
        Link::factory()->withSlug('old0001')->expired()->create();
        $capped = Link::factory()->withSlug('cap0001')->capped(1)->create();

        $this->get('/off0001')->assertStatus(410);
        $this->get('/old0001')->assertStatus(410);

        // First click is allowed, the second trips the cap using the live
        // Redis counter rather than waiting for the drain.
        $this->get('/cap0001')->assertStatus(302);
        $this->get('/cap0001')->assertStatus(410);

        $this->assertSame(1, app(ClickBuffer::class)->liveClicks($capped->id));
    }

    public function test_the_click_is_buffered_and_only_persisted_by_the_drain(): void
    {
        $link = Link::factory()->withSlug('buf0001')->create();

        $this->withHeaders([
            'Referer' => 'https://news.ycombinator.com/item?id=1',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Version/17.4 Mobile/15E148 Safari/604.1',
            'CloudFront-Viewer-Country' => 'de',
        ])->get('/buf0001')->assertStatus(302);

        // Nothing hit MySQL yet: the redirect must not pay for the write.
        $this->assertSame(0, ClickEvent::count());
        $this->assertSame(1, app(ClickBuffer::class)->depth());

        $persisted = (new ProcessClickBatch)->handle(
            app(ClickBuffer::class),
            app(UserAgentParser::class),
            app(GeoResolver::class),
        );

        $this->assertSame(1, $persisted);

        $click = ClickEvent::firstOrFail();
        $this->assertSame($link->id, $click->link_id);
        $this->assertSame('news.ycombinator.com', $click->referrer_host);
        $this->assertSame('mobile', $click->device_type);
        $this->assertSame('Safari', $click->browser);
        $this->assertSame('DE', $click->country);
        $this->assertFalse($click->is_bot);

        $this->assertSame(1, $link->fresh()->click_count);
        $this->assertNotNull($link->fresh()->last_clicked_at);
    }

    public function test_direct_traffic_is_labelled_rather_than_null(): void
    {
        Link::factory()->withSlug('dir0001')->create();

        $this->get('/dir0001');

        (new ProcessClickBatch)->handle(
            app(ClickBuffer::class),
            app(UserAgentParser::class),
            app(GeoResolver::class),
        );

        $this->assertSame('direct', ClickEvent::firstOrFail()->referrer_host);
    }

    public function test_raw_ip_addresses_are_not_stored_by_default(): void
    {
        Link::factory()->withSlug('pri0001')->create();

        $this->get('/pri0001');

        (new ProcessClickBatch)->handle(
            app(ClickBuffer::class),
            app(UserAgentParser::class),
            app(GeoResolver::class),
        );

        $click = ClickEvent::firstOrFail();
        $this->assertNull($click->ip_address);
        $this->assertNotNull($click->visitor_hash, 'a salted hash still supports unique-visitor counts');
    }

    public function test_slugs_with_illegal_characters_never_reach_the_resolver(): void
    {
        $this->get('/not-a-valid-slug!')->assertStatus(404);
    }
}
