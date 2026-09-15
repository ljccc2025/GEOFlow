<?php

namespace Tests\Feature;

use App\Data\Ai\SystemAiIdentity;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\AiVisibilityCollectionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiVisibilityCollectionDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_collects_from_both_overseas_engines_for_the_same_keyword(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.perplexity.ai/v1/sonar' => Http::response([
                'id' => 'pplx-d',
                'model' => 'sonar',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Perplexity 回答']]],
                'citations' => ['https://example.com/p'],
            ], 200),
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp-d',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'OpenAI 回答']]]],
            ], 200),
        ]);

        $this->createProvider(AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH, 'https://api.perplexity.ai/v1/sonar');
        $this->createProvider(AiSourceProvider::PROVIDER_OPENAI_WEB_SEARCH, 'https://api.openai.com/v1/responses');

        $runs = $this->app->make(AiVisibilityCollectionService::class)
            ->collect(SystemAiIdentity::visibilityCollection(), '中国耳塞设计工厂');

        $this->assertCount(2, $runs);
        $this->assertArrayHasKey('perplexity_run', $runs);
        $this->assertArrayHasKey('openai_run', $runs);
        $this->assertSame(AiVisibilityRun::PROVIDER_PERPLEXITY_SEARCH, $runs['perplexity_run']->provider_type);
        $this->assertSame(AiVisibilityRun::PROVIDER_OPENAI_WEB_SEARCH, $runs['openai_run']->provider_type);
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $runs['perplexity_run']->status);
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $runs['openai_run']->status);
        $this->assertSame('Perplexity 回答', $runs['perplexity_run']->answer_text);
        $this->assertSame('OpenAI 回答', $runs['openai_run']->answer_text);
    }

    public function test_it_falls_back_to_domestic_when_no_overseas_provider_configured(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'domestic-fallback-log',
                'Result' => ['WebResults' => []],
            ]),
        ]);

        // 仅配置国内豆包 search 信源、不配置任何海外信源：
        // collect 应回落 collectDomestic（与改造前 collect 的国内分支逐行等价），
        // 返回国内 search_run，且不应产生任何海外 run。
        $this->createProvider(AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM, 'https://open.feedcoopapi.com/search_api/web_search');

        $runs = $this->app->make(AiVisibilityCollectionService::class)
            ->collect(SystemAiIdentity::visibilityCollection(), '中国耳塞设计工厂');

        $this->assertArrayHasKey('search_run', $runs);
        $this->assertArrayNotHasKey('perplexity_run', $runs);
        $this->assertArrayNotHasKey('openai_run', $runs);
        $this->assertSame(AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM, $runs['search_run']->provider_type);
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $runs['search_run']->status);
        // 仅国内豆包链路发起一次 HTTP，海外引擎未被调用
        Http::assertSentCount(1);
    }

    private function createProvider(string $providerKey, string $endpointUrl): AiSourceProvider
    {
        return AiSourceProvider::query()->create([
            'name' => 'test-'.$providerKey,
            'provider_key' => $providerKey,
            'endpoint_url' => $endpointUrl,
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'status' => 'active',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'metadata_json' => [],
        ]);
    }
}
