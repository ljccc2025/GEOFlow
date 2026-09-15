<?php

namespace Tests\Feature;

use App\Data\Ai\SystemAiIdentity;
use App\Models\AiSourceProvider;
use App\Services\GeoFlow\AiVisibility\AiProviderEndpointPolicy;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiVisibilityConfigurationResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): AiVisibilityConfigurationResolver
    {
        return new AiVisibilityConfigurationResolver(new AiProviderEndpointPolicy());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProvider(array $overrides = []): AiSourceProvider
    {
        return AiSourceProvider::query()->create(array_merge([
            'name' => 'test-search',
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-search-key'),
            'status' => 'active',
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'metadata_json' => [
                'count' => 10,
                'search_type' => 'web',
                'need_summary' => true,
                'need_content' => true,
                'need_url' => true,
                'content_formats' => 'Markdown',
                'sites' => [],
                'block_hosts' => [],
            ],
        ], $overrides));
    }

    public function test_search_provider_defaults_to_doubao(): void
    {
        $identity = SystemAiIdentity::visibilityCollection();
        $this->createProvider();
        $this->createProvider([
            'name' => 'perplexity',
            'provider_key' => AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH,
            'endpoint_url' => 'https://api.perplexity.ai',
        ]);
        $this->createProvider([
            'name' => 'openai',
            'provider_key' => AiSourceProvider::PROVIDER_OPENAI_WEB_SEARCH,
            'endpoint_url' => 'https://api.openai.com/v1/responses',
        ]);

        $provider = $this->resolver()->searchProvider($identity);

        $this->assertInstanceOf(AiSourceProvider::class, $provider);
        $this->assertSame(AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM, $provider->provider_key);
    }

    public function test_perplexity_provider_resolves_its_own_source(): void
    {
        $identity = SystemAiIdentity::visibilityCollection();
        $this->createProvider();
        $this->createProvider([
            'name' => 'perplexity',
            'provider_key' => AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH,
            'endpoint_url' => 'https://api.perplexity.ai',
        ]);

        $provider = $this->resolver()->perplexityProvider($identity);

        $this->assertInstanceOf(AiSourceProvider::class, $provider);
        $this->assertSame(AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH, $provider->provider_key);
    }

    public function test_openai_provider_resolves_its_own_source(): void
    {
        $identity = SystemAiIdentity::visibilityCollection();
        $this->createProvider();
        $this->createProvider([
            'name' => 'openai',
            'provider_key' => AiSourceProvider::PROVIDER_OPENAI_WEB_SEARCH,
            'endpoint_url' => 'https://api.openai.com/v1/responses',
        ]);

        $provider = $this->resolver()->openAiProvider($identity);

        $this->assertInstanceOf(AiSourceProvider::class, $provider);
        $this->assertSame(AiSourceProvider::PROVIDER_OPENAI_WEB_SEARCH, $provider->provider_key);
    }

    public function test_missing_target_engine_returns_null(): void
    {
        $identity = SystemAiIdentity::visibilityCollection();
        $this->createProvider();

        $provider = $this->resolver()->perplexityProvider($identity);

        $this->assertNull($provider);
    }

    public function test_provider_without_api_key_is_skipped(): void
    {
        $identity = SystemAiIdentity::visibilityCollection();
        $this->createProvider([
            'name' => 'perplexity',
            'provider_key' => AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH,
            'endpoint_url' => 'https://api.perplexity.ai',
            'api_key' => '',
        ]);

        $provider = $this->resolver()->perplexityProvider($identity);

        $this->assertNull($provider);
    }
}
