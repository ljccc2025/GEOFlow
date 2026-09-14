<?php

namespace Tests\Unit;

use App\Models\AiVisibilityRun;
use PHPUnit\Framework\TestCase;

class AiVisibilitySampleProvidersTest extends TestCase
{
    public function test_it_includes_overseas_engines_in_sample_providers(): void
    {
        $this->assertContains(AiVisibilityRun::PROVIDER_PERPLEXITY_SEARCH, AiVisibilityRun::SAMPLE_PROVIDERS);
        $this->assertContains(AiVisibilityRun::PROVIDER_OPENAI_WEB_SEARCH, AiVisibilityRun::SAMPLE_PROVIDERS);
    }

    public function test_it_keeps_existing_domestic_providers(): void
    {
        $this->assertContains(AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES, AiVisibilityRun::SAMPLE_PROVIDERS);
        $this->assertContains(AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM, AiVisibilityRun::SAMPLE_PROVIDERS);
        $this->assertContains(AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS, AiVisibilityRun::SAMPLE_PROVIDERS);
    }

    public function test_provider_constants_have_expected_values(): void
    {
        $this->assertSame('perplexity_search', AiVisibilityRun::PROVIDER_PERPLEXITY_SEARCH);
        $this->assertSame('openai_web_search', AiVisibilityRun::PROVIDER_OPENAI_WEB_SEARCH);
    }
}
