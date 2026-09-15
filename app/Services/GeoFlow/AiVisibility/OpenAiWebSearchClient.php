<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiSourceProvider;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

final class OpenAiWebSearchClient
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiVisibilityHttpClientFactory $httpClientFactory,
        private readonly AiVisibilityResultNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function search(AiSourceProvider $provider, string $query, array $options = []): AiVisibilityResult
    {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('OpenAI 查询词为空');
        }

        $endpoint = $this->endpoint($provider);
        $apiKey = $this->apiKey($provider);
        $payload = $this->buildPayload($query, $options);

        $startedAt = hrtime(true);
        $response = $this->httpClientFactory
            ->jsonRequest($apiKey)
            ->post($endpoint, $payload);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'OpenAI 请求失败：HTTP %d %s',
                $response->status(),
                trim($response->body())
            ));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('OpenAI 返回了非 JSON 结构');
        }

        return $this->normalizer->normalizeOpenAiWebSearch($json, [
            'endpoint' => $endpoint,
            'payload' => $payload,
            'model' => $payload['model'] ?? '',
        ], $latencyMs);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildPayload(string $query, array $options): array
    {
        return array_filter([
            'model' => (string) ($options['model'] ?? config('geoflow.ai_visibility.openai_model', 'gpt-4o')),
            'input' => $query,
            'tools' => [['type' => 'web_search']],
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function endpoint(AiSourceProvider $provider): string
    {
        $endpoint = trim((string) ($provider->endpoint_url ?? ''));
        if ($endpoint === '') {
            $endpoint = trim((string) config('geoflow.ai_visibility.openai_endpoint', ''));
        }

        if ($endpoint === '') {
            throw new RuntimeException('OpenAI Endpoint 为空');
        }

        return $endpoint;
    }

    private function apiKey(AiSourceProvider $provider): string
    {
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($provider->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API Key 为空');
        }

        return $apiKey;
    }
}
