<?php

namespace Tests\Feature;

use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\AiVisibilityResult;
use App\Services\GeoFlow\AiVisibility\AiVisibilityService;
use App\Services\GeoFlow\AiVisibility\AiVisibilitySourceData;
use App\Services\GeoFlow\AiVisibility\PerplexitySearchClient;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AiVisibilityPerplexityRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_perplexity_run(): void
    {
        $provider = $this->createPerplexityProvider();

        $client = Mockery::mock(PerplexitySearchClient::class);
        $client->shouldReceive('search')->once()->andReturn(new AiVisibilityResult(
            providerType: AiVisibilityRun::PROVIDER_PERPLEXITY_SEARCH,
            providerKey: AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH,
            modelId: 'sonar',
            answerText: '推荐 A 公司。',
            sources: [new AiVisibilitySourceData(
                sourceType: 'perplexity_citation',
                url: 'https://example.com/a',
                title: 'A',
                publishedAt: null,
                rank: 1,
            )],
            usage: [],
            metadata: [],
            rawRequest: [],
            rawResponse: [],
            latencyMs: 12,
        ));

        $this->app->instance(PerplexitySearchClient::class, $client);

        $run = $this->app->make(AiVisibilityService::class)->runPerplexitySearch($provider, '中国耳塞设计工厂');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('推荐 A 公司。', $run->answer_text);
        $this->assertDatabaseHas('ai_visibility_sources', ['url' => 'https://example.com/a']);
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
