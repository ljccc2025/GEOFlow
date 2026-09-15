<?php

namespace Tests\Unit;

use App\Models\AiSourceProvider;
use App\Services\GeoFlow\AiVisibility\AiVisibilityHttpClientFactory;
use App\Services\GeoFlow\AiVisibility\AiVisibilityResultNormalizer;
use App\Services\GeoFlow\AiVisibility\PerplexitySearchClient;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\Client\Response;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PerplexitySearchClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_posts_the_query_and_normalizes_the_answer(): void
    {
        $provider = $this->provider();
        $response = new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'id' => 'pplx-1',
            'model' => 'sonar',
            'choices' => [['message' => ['content' => '推荐 A 公司。']]],
            'citations' => ['https://example.com/a'],
        ])));

        $request = Mockery::mock();
        $request->shouldReceive('post')->once()->with('https://api.perplexity.ai/v1/sonar', Mockery::on(
            fn (array $payload): bool => ($payload['model'] ?? null) === 'sonar'
                && ($payload['messages'][0]['content'] ?? null) === '中国耳塞设计工厂'
        ))->andReturn($response);

        $factory = Mockery::mock(AiVisibilityHttpClientFactory::class);
        $factory->shouldReceive('jsonRequest')->once()->with('secret-key')->andReturn($request);

        $crypto = Mockery::mock(ApiKeyCrypto::class);
        $crypto->shouldReceive('decrypt')->once()->andReturn('secret-key');

        $client = new PerplexitySearchClient($crypto, $factory, new AiVisibilityResultNormalizer);
        $result = $client->search($provider, '中国耳塞设计工厂');

        $this->assertSame('推荐 A 公司。', $result->answerText);
        $this->assertCount(1, $result->sources);
    }

    public function test_it_throws_on_empty_query(): void
    {
        $client = new PerplexitySearchClient(
            Mockery::mock(ApiKeyCrypto::class),
            Mockery::mock(AiVisibilityHttpClientFactory::class),
            new AiVisibilityResultNormalizer,
        );

        $this->expectException(RuntimeException::class);
        $client->search($this->provider(), '   ');
    }

    public function test_it_throws_on_non_successful_response(): void
    {
        $provider = $this->provider();
        $response = new Response(new \GuzzleHttp\Psr7\Response(429, [], 'rate limited'));

        $request = Mockery::mock();
        $request->shouldReceive('post')->once()->andReturn($response);

        $factory = Mockery::mock(AiVisibilityHttpClientFactory::class);
        $factory->shouldReceive('jsonRequest')->once()->andReturn($request);

        $crypto = Mockery::mock(ApiKeyCrypto::class);
        $crypto->shouldReceive('decrypt')->once()->andReturn('secret-key');

        $client = new PerplexitySearchClient($crypto, $factory, new AiVisibilityResultNormalizer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 429/');
        $client->search($provider, 'query');
    }

    private function provider(): AiSourceProvider
    {
        $provider = new AiSourceProvider;
        $provider->provider_key = AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH;
        $provider->endpoint_url = 'https://api.perplexity.ai/v1/sonar';
        $provider->setRawAttributes(['api_key' => 'encrypted'], true);

        return $provider;
    }
}
