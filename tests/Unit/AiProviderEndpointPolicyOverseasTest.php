<?php

namespace Tests\Unit;

use App\Services\GeoFlow\AiVisibility\AiProviderEndpointPolicy;
use PHPUnit\Framework\TestCase;

class AiProviderEndpointPolicyOverseasTest extends TestCase
{
    public function test_it_accepts_perplexity_search_endpoint(): void
    {
        $this->assertTrue(
            (new AiProviderEndpointPolicy)->acceptsSearchApi('https://api.perplexity.ai/chat/completions')
        );
    }

    public function test_it_accepts_openai_search_endpoint(): void
    {
        $this->assertTrue(
            (new AiProviderEndpointPolicy)->acceptsSearchApi('https://api.openai.com/v1/responses')
        );
    }

    public function test_it_still_rejects_unlisted_host(): void
    {
        $this->assertFalse(
            (new AiProviderEndpointPolicy)->acceptsSearchApi('https://evil.example.com/v1/responses')
        );
    }

    public function test_it_rejects_plain_http_scheme(): void
    {
        $this->assertFalse(
            (new AiProviderEndpointPolicy)->acceptsSearchApi('http://api.perplexity.ai/chat/completions')
        );
    }

    public function test_it_rejects_host_suffix_lookalike(): void
    {
        $this->assertFalse(
            (new AiProviderEndpointPolicy)->acceptsSearchApi('https://api.openai.com.attacker.example/v1/responses')
        );
    }

    public function test_it_rejects_url_with_credentials(): void
    {
        $this->assertFalse(
            (new AiProviderEndpointPolicy)->acceptsSearchApi('https://user:pass@api.openai.com/v1/responses')
        );
    }

    public function test_it_rejects_non_api_subdomain_of_perplexity(): void
    {
        $this->assertFalse(
            (new AiProviderEndpointPolicy)->acceptsSearchApi('https://evil.perplexity.ai/x')
        );
    }
}
