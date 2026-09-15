<?php

namespace Tests\Unit;

use App\Models\AiSourceProvider;
use App\Services\GeoFlow\AiVisibility\AiVisibilityHttpClientFactory;
use App\Services\GeoFlow\AiVisibility\AiVisibilityResultNormalizer;
use App\Services\GeoFlow\AiVisibility\PerplexitySearchClient;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PerplexitySearchClientTest extends TestCase
{
    public function test_it_posts_the_query_and_normalizes_the_answer(): void
    {
        $crypto = app(ApiKeyCrypto::class);
        $factory = app(AiVisibilityHttpClientFactory::class);
        $client = new PerplexitySearchClient($crypto, $factory, new AiVisibilityResultNormalizer);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.perplexity.ai/v1/sonar' => Http::response([
                'id' => 'pplx-1',
                'model' => 'sonar',
                'choices' => [['message' => ['content' => '推荐 A 公司。']]],
                'citations' => ['https://example.com/a'],
            ], 200),
        ]);

        $result = $client->search($this->provider($crypto), '中国耳塞设计工厂');

        $this->assertSame('推荐 A 公司。', $result->answerText);
        $this->assertCount(1, $result->sources);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.perplexity.ai/v1/sonar'
                && ($request['model'] ?? null) === 'sonar'
                && ($request['messages'][0]['content'] ?? null) === '中国耳塞设计工厂'
                && $request->hasHeader('Authorization', 'Bearer test-perplexity-key');
        });
    }

    public function test_it_throws_on_empty_query(): void
    {
        $crypto = app(ApiKeyCrypto::class);
        $factory = app(AiVisibilityHttpClientFactory::class);
        $client = new PerplexitySearchClient($crypto, $factory, new AiVisibilityResultNormalizer);

        Http::preventStrayRequests();

        $this->expectException(RuntimeException::class);
        $client->search($this->provider($crypto), '   ');
    }

    public function test_it_throws_on_non_successful_response(): void
    {
        $crypto = app(ApiKeyCrypto::class);
        $factory = app(AiVisibilityHttpClientFactory::class);
        $client = new PerplexitySearchClient($crypto, $factory, new AiVisibilityResultNormalizer);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.perplexity.ai/v1/sonar' => Http::response('rate limited', 429),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 429/');
        $client->search($this->provider($crypto), 'query');
    }

    private function provider(ApiKeyCrypto $crypto): AiSourceProvider
    {
        $provider = new AiSourceProvider;
        $provider->provider_key = AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH;
        $provider->endpoint_url = 'https://api.perplexity.ai/v1/sonar';
        $provider->setRawAttributes(['api_key' => $crypto->encrypt('test-perplexity-key')], true);

        return $provider;
    }
}
