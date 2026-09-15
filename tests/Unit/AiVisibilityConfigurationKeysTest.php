<?php

namespace Tests\Unit;

use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use ReflectionClass;
use Tests\TestCase;

class AiVisibilityConfigurationKeysTest extends TestCase
{
    public function test_it_exposes_config_keys_for_overseas_engines(): void
    {
        $this->assertSame('ai_visibility_perplexity_provider_id', AiVisibilityConfigurationResolver::PERPLEXITY_PROVIDER_SETTING_KEY);
        $this->assertSame('ai_visibility_openai_provider_id', AiVisibilityConfigurationResolver::OPENAI_PROVIDER_SETTING_KEY);
    }

    public function test_search_provider_is_not_hardcoded_to_doubao(): void
    {
        $source = file_get_contents((new ReflectionClass(AiVisibilityConfigurationResolver::class))->getFileName());

        $this->assertStringNotContainsString(
            "->where('provider_key', AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM)",
            $source,
            '按引擎解析信源时不得把 provider_key 硬编码为豆包，否则新引擎永远选不中。'
        );
    }
}
