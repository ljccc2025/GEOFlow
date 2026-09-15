<?php

namespace Tests\Feature;

use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\AiVisibilityService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiVisibilityPerplexityRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_perplexity_run(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.perplexity.ai/v1/sonar' => Http::response([
                'id' => 'pplx-1',
                'model' => 'sonar',
                'choices' => [['message' => ['role' => 'assistant', 'content' => '推荐 A 公司。']]],
                'citations' => ['https://example.com/a'],
                'search_results' => [['title' => 'A 公司官网', 'url' => 'https://example.com/a', 'date' => '2026-01-01']],
                'usage' => ['total_tokens' => 42],
            ], 200),
        ]);

        $provider = $this->createPerplexityProvider();

        $service = $this->app->make(AiVisibilityService::class);
        $run = $service->runPerplexitySearch($provider, '中国耳塞设计工厂');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('推荐 A 公司。', $run->answer_text);
        $this->assertDatabaseHas('ai_visibility_sources', ['url' => 'https://example.com/a']);
        $this->assertSame(1, $run->sources()->count());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.perplexity.ai/v1/sonar'
            && ($request['model'] ?? null) === 'sonar'
            && ($request['messages'][0]['content'] ?? null) === '中国耳塞设计工厂'
            && $request->hasHeader('Authorization', 'Bearer test-perplexity-key'));

        $this->assertSame(1, (int) $provider->fresh()->used_today);
    }

    private function createPerplexityProvider(): AiSourceProvider
    {
        return AiSourceProvider::query()->create([
            'name' => 'test-perplexity',
            'provider_key' => AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH,
            'endpoint_url' => 'https://api.perplexity.ai/v1/sonar',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-perplexity-key'),
            'status' => 'active',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'metadata_json' => [],
        ]);
    }
}
