<?php

namespace Tests\Unit;

use App\Models\AiSourceProvider;
use App\Services\GeoFlow\AiVisibility\AiVisibilityHttpClientFactory;
use App\Services\GeoFlow\AiVisibility\AiVisibilityResultNormalizer;
use App\Services\GeoFlow\AiVisibility\OpenAiWebSearchClient;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiWebSearchClientTest extends TestCase
{
    public function test_it_enables_the_web_search_tool(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_1',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Answer']]]],
            ], 200),
        ]);

        $client = new OpenAiWebSearchClient(
            app(ApiKeyCrypto::class),
            app(AiVisibilityHttpClientFactory::class),
            new AiVisibilityResultNormalizer,
        );
        $result = $client->search($this->provider(), 'earplug factory china');

        $this->assertSame('Answer', $result->answerText);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses'
            && ($request['tools'][0]['type'] ?? null) === 'web_search'
            && ($request['input'] ?? null) === 'earplug factory china'
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_it_throws_on_empty_query(): void
    {
        Http::preventStrayRequests();

        $client = new OpenAiWebSearchClient(
            app(ApiKeyCrypto::class),
            app(AiVisibilityHttpClientFactory::class),
            new AiVisibilityResultNormalizer,
        );

        $this->expectException(RuntimeException::class);
        $client->search($this->provider(), '   ');
    }

    public function test_it_throws_on_non_successful_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response('rate limited', 429),
        ]);

        $client = new OpenAiWebSearchClient(
            app(ApiKeyCrypto::class),
            app(AiVisibilityHttpClientFactory::class),
            new AiVisibilityResultNormalizer,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 429/');
        $client->search($this->provider(), 'query');
    }

    private function provider(): AiSourceProvider
    {
        $provider = new AiSourceProvider;
        $provider->provider_key = AiSourceProvider::PROVIDER_OPENAI_WEB_SEARCH;
        $provider->endpoint_url = 'https://api.openai.com/v1/responses';
        $provider->setRawAttributes(['api_key' => app(ApiKeyCrypto::class)->encrypt('sk-test')], true);

        return $provider;
    }
}
