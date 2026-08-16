<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Link;
use App\Services\RedisLinkStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLinkStore;
use Tests\TestCase;

class LinkApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeLinkStore $store;

    private string $token = 'lf_testtoken0000000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeLinkStore;
        $this->app->instance(RedisLinkStore::class, $this->store);

        ApiToken::factory()->withPlaintext($this->token)->create(['name' => 'tests']);
    }

    private function auth(array $headers = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$this->token], $headers);
    }

    public function test_it_rejects_requests_without_a_token(): void
    {
        $this->getJson('/api/links')->assertStatus(401);
    }

    public function test_it_rejects_an_unknown_token(): void
    {
        $this->getJson('/api/links', ['Authorization' => 'Bearer lf_nope'])->assertStatus(401);
    }

    public function test_it_enforces_token_abilities(): void
    {
        $plaintext = 'lf_readonlytoken000000000000000000000000000000000';
        ApiToken::factory()->readOnly()->withPlaintext($plaintext)->create();

        $headers = ['Authorization' => 'Bearer '.$plaintext];

        $this->getJson('/api/links', $headers)->assertStatus(200);
        $this->postJson('/api/links', ['target_url' => 'https://example.com'], $headers)->assertStatus(403);
    }

    public function test_it_creates_a_link_with_a_generated_slug(): void
    {
        $response = $this->postJson('/api/links', [
            'target_url' => 'https://example.com/launch',
            'title' => 'Launch page',
        ], $this->auth());

        $response->assertStatus(201)
            ->assertJsonPath('data.target_url', 'https://example.com/launch')
            ->assertJsonPath('data.is_custom_alias', false)
            ->assertJsonStructure(['data' => ['id', 'slug', 'short_url', 'click_count']]);

        $slug = $response->json('data.slug');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{4,32}$/', $slug);
        $this->assertSame('example.com', Link::firstOrFail()->domain);

        // The new link is written through to the cache so the first redirect
        // does not miss (and cannot read a stale negative-cache sentinel).
        $this->assertArrayHasKey($this->store->slugKey($slug), $this->store->data);
    }

    public function test_it_accepts_a_custom_alias(): void
    {
        $this->postJson('/api/links', [
            'target_url' => 'https://example.com/docs',
            'alias' => 'docs2026',
        ], $this->auth())
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'docs2026')
            ->assertJsonPath('data.is_custom_alias', true);
    }

    public function test_it_rejects_a_taken_or_reserved_alias(): void
    {
        Link::factory()->withSlug('taken01')->create();

        $this->postJson('/api/links', ['target_url' => 'https://example.com', 'alias' => 'taken01'], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('alias');

        $this->postJson('/api/links', ['target_url' => 'https://example.com', 'alias' => 'dashboard'], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('alias');
    }

    public function test_it_rejects_unsafe_targets(): void
    {
        foreach ([
            'javascript:alert(1)',
            'ftp://files.example.com/x',
            'http://169.254.169.254/latest/meta-data/',
            'http://localhost:8080/admin',
            'http://10.0.0.5/internal',
            'not a url',
        ] as $target) {
            $this->postJson('/api/links', ['target_url' => $target], $this->auth())
                ->assertStatus(422)
                ->assertJsonValidationErrors('target_url');
        }
    }

    public function test_it_lists_links_with_search_and_sorting(): void
    {
        Link::factory()->withSlug('alpha01')->create(['title' => 'Alpha campaign', 'click_count' => 5]);
        Link::factory()->withSlug('beta002')->create(['title' => 'Beta campaign', 'click_count' => 50]);

        $this->getJson('/api/links?sort=click_count&direction=desc', $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('data.0.slug', 'beta002')
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/links?q=Alpha', $this->auth())
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'alpha01');
    }

    public function test_updating_a_link_writes_the_new_target_through_the_cache(): void
    {
        $link = Link::factory()->withSlug('retarg1')->create(['target_url' => 'https://example.com/old']);

        $this->patchJson("/api/links/{$link->id}", [
            'target_url' => 'https://example.com/new',
        ], $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('data.target_url', 'https://example.com/new');

        $cached = json_decode($this->store->data[$this->store->slugKey('retarg1')], true);

        $this->assertSame('https://example.com/new', $cached[2]);
    }

    public function test_disabling_a_link_is_reflected_in_the_cache(): void
    {
        $link = Link::factory()->withSlug('toggle1')->create();

        $this->patchJson("/api/links/{$link->id}", ['is_active' => false], $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.is_redirectable', false);

        $this->get('/toggle1')->assertStatus(410);
    }

    public function test_deleting_a_link_soft_deletes_it_and_evicts_the_cache(): void
    {
        $link = Link::factory()->withSlug('delete1')->create();
        $this->get('/delete1')->assertStatus(302);

        $this->deleteJson("/api/links/{$link->id}", [], $this->auth())->assertStatus(204);

        $this->assertSoftDeleted('links', ['id' => $link->id]);
        $this->assertArrayNotHasKey($this->store->slugKey('delete1'), $this->store->data);
    }

    public function test_bulk_creation(): void
    {
        $this->postJson('/api/links/bulk', [
            'links' => [
                ['target_url' => 'https://example.com/a'],
                ['target_url' => 'https://example.com/b'],
                ['target_url' => 'https://example.com/c'],
            ],
        ], $this->auth())
            ->assertStatus(201)
            ->assertJsonCount(3, 'data');

        $this->assertSame(3, Link::count());
        $this->assertSame(3, Link::distinct()->count('slug'));
    }

    public function test_health_endpoint_reports_dependencies(): void
    {
        $this->getJson('/api/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['checks' => ['redis' => ['ok'], 'mysql' => ['ok']]]);
    }
}
