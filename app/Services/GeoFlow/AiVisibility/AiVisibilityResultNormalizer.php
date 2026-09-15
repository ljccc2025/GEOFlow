<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use Carbon\CarbonImmutable;
use Throwable;

final class AiVisibilityResultNormalizer
{
    /**
     * @param  array<string,mixed>  $response
     * @param  array<string,mixed>  $request
     */
    public function normalizeArkResponses(array $response, array $request, string $modelId, int $latencyMs): AiVisibilityResult
    {
        $parsed = $this->parseResponsesPayload($response);

        $fallbackText = $this->stringValue($response['output_text'] ?? '');
        $answerText = trim(implode("\n\n", array_filter($parsed['segments'], static fn (string $segment): bool => trim($segment) !== '')));
        if ($answerText === '' && $fallbackText !== '') {
            $answerText = $fallbackText;
        }

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new AiVisibilityResult(
            providerType: AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
            providerKey: 'doubao_ark',
            modelId: $modelId,
            answerText: $answerText,
            sources: $parsed['sources'],
            usage: $usage,
            metadata: array_filter([
                'response_id' => $parsed['response_id'],
                'web_search_calls' => $parsed['web_search_calls'],
                'tool_usage' => is_array($usage['tool_usage'] ?? null) ? $usage['tool_usage'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
            rawRequest: $request,
            rawResponse: $response,
            latencyMs: $latencyMs,
        );
    }

    /**
     * 解析 OpenAI Responses 形状的载荷（Ark 与 OpenAI 官方同构）。
     *
     * @param  array<string,mixed>  $response
     * @return array{segments: list<string>, sources: list<AiVisibilitySourceData>, web_search_calls: list<array<string,mixed>>, response_id: string}
     */
    private function parseResponsesPayload(array $response): array
    {
        $answerSegments = [];
        $sources = [];
        $webSearchCalls = [];

        $output = $response['output'] ?? [];
        if (! is_array($output)) {
            $output = [];
        }

        foreach ($output as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $this->stringValue($item['type'] ?? '');
            if ($type === 'web_search_call') {
                $webSearchCalls[] = array_filter([
                    'id' => $this->stringValue($item['id'] ?? ''),
                    'status' => $this->stringValue($item['status'] ?? ''),
                    'action' => is_array($item['action'] ?? null) ? $item['action'] : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');

                continue;
            }

            if ($type !== 'message') {
                continue;
            }

            $content = $item['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $text = $this->stringValue($part['text'] ?? '');
                if ($text !== '') {
                    $answerSegments[] = $text;
                }

                $annotations = $part['annotations'] ?? [];
                if (! is_array($annotations)) {
                    continue;
                }

                foreach ($annotations as $annotation) {
                    if (! is_array($annotation)) {
                        continue;
                    }
                    $source = $this->annotationToSource($annotation, count($sources) + 1);
                    if ($source !== null) {
                        $sources = $this->appendUniqueSource($sources, $source);
                    }
                }
            }
        }

        return [
            'segments' => $answerSegments,
            'sources' => $sources,
            'web_search_calls' => $webSearchCalls,
            'response_id' => $this->stringValue($response['id'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $response
     * @param  array<string,mixed>  $request
     */
    public function normalizePerplexity(array $response, array $request, int $latencyMs): AiVisibilityResult
    {
        $segments = [];
        $choices = $response['choices'] ?? [];
        if (is_array($choices)) {
            foreach ($choices as $choice) {
                if (! is_array($choice)) {
                    continue;
                }
                $content = $this->stringValue($choice['message']['content'] ?? '');
                if ($content !== '') {
                    $segments[] = $content;
                }
            }
        }

        $sources = [];
        $searchResults = $response['search_results'] ?? [];
        if (is_array($searchResults)) {
            foreach ($searchResults as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $url = $this->stringValue($item['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $sources = $this->appendUniqueSource($sources, new AiVisibilitySourceData(
                    sourceType: 'perplexity_citation',
                    url: $url,
                    title: $this->stringValue($item['title'] ?? ''),
                    publishedAt: $this->parsePublishedAt($item['date'] ?? null),
                    rank: count($sources) + 1,
                ));
            }
        }

        $citations = $response['citations'] ?? [];
        if (is_array($citations)) {
            foreach ($citations as $citation) {
                $url = $this->stringValue(is_string($citation) ? $citation : ($citation['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $sources = $this->appendUniqueSource($sources, new AiVisibilitySourceData(
                    sourceType: 'perplexity_citation',
                    url: $url,
                    title: '',
                    publishedAt: null,
                    rank: count($sources) + 1,
                ));
            }
        }

        return new AiVisibilityResult(
            providerType: AiVisibilityRun::PROVIDER_PERPLEXITY_SEARCH,
            providerKey: AiSourceProvider::PROVIDER_PERPLEXITY_SEARCH,
            modelId: $this->stringValue($response['model'] ?? ''),
            answerText: trim(implode("\n\n", array_filter($segments, static fn (string $s): bool => trim($s) !== ''))),
            sources: $sources,
            usage: is_array($response['usage'] ?? null) ? $response['usage'] : [],
            metadata: array_filter([
                'response_id' => $this->stringValue($response['id'] ?? ''),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            rawRequest: $request,
            rawResponse: $response,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  array<string,mixed>  $response
     * @param  array<string,mixed>  $request
     */
    public function normalizeDoubaoSearchCustom(array $response, array $request, int $latencyMs): AiVisibilityResult
    {
        $result = is_array($response['Result'] ?? null) ? $response['Result'] : [];
        $items = is_array($result['WebResults'] ?? null) ? $result['WebResults'] : [];
        $sources = [];

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $this->stringValue($item['Url'] ?? '');
            $sources = $this->appendUniqueSource($sources, new AiVisibilitySourceData(
                sourceType: 'web_search_result',
                citationKey: 'S'.((string) ($index + 1)),
                title: $this->nullableString($item['Title'] ?? null),
                url: $url !== '' ? $url : null,
                domain: $this->domainFromUrl($url),
                siteName: $this->nullableString($item['SiteName'] ?? null),
                snippet: $this->nullableString($item['Snippet'] ?? null),
                summary: $this->nullableString($item['Summary'] ?? null),
                contentExcerpt: $this->limitText($this->nullableString($item['Content'] ?? null), 12000),
                publishedAt: $this->parsePublishedAt($item['PublishTime'] ?? null),
                rank: $index + 1,
                rankScore: $this->nullableFloat($item['RankScore'] ?? null),
                authorityLevel: $this->nullableString($item['AuthInfoLevel'] ?? null),
                metadata: array_filter([
                    'raw_rank_score' => $item['RankScore'] ?? null,
                    'source_type' => $this->nullableString($item['SourceType'] ?? null),
                    'host' => $this->nullableString($item['Host'] ?? null),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ));
        }

        return new AiVisibilityResult(
            providerType: AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            providerKey: 'doubao_search_custom',
            modelId: null,
            answerText: '',
            sources: $sources,
            usage: [],
            metadata: array_filter([
                'log_id' => $this->stringValue($response['LogId'] ?? $result['LogId'] ?? ''),
                'time_cost' => $result['TimeCost'] ?? null,
                'search_context' => is_array($result['SearchContext'] ?? null) ? $result['SearchContext'] : null,
                'result_count' => count($sources),
            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
            rawRequest: $request,
            rawResponse: $response,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $rawResponse
     * @param  array<string,mixed>  $usage
     * @param  list<AiVisibilitySourceData>  $sources
     */
    public function normalizeTextAnalysis(
        string $providerType,
        ?string $providerKey,
        string $modelId,
        string $answerText,
        array $sources,
        array $usage,
        array $request,
        array $rawResponse,
        int $latencyMs,
    ): AiVisibilityResult {
        return new AiVisibilityResult(
            providerType: $providerType,
            providerKey: $providerKey,
            modelId: $modelId,
            answerText: $answerText,
            sources: $sources,
            usage: $usage,
            metadata: [
                'source_count' => count($sources),
            ],
            rawRequest: $request,
            rawResponse: $rawResponse,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  array<string,mixed>  $annotation
     */
    private function annotationToSource(array $annotation, int $position): ?AiVisibilitySourceData
    {
        $source = is_array($annotation['source'] ?? null) ? $annotation['source'] : [];
        $url = $this->stringValue($annotation['url'] ?? $annotation['uri'] ?? ($source['url'] ?? ''));
        $title = $this->stringValue($annotation['title'] ?? ($source['title'] ?? ''));

        if ($url === '' && $title === '') {
            return null;
        }

        return new AiVisibilitySourceData(
            sourceType: 'native_annotation',
            citationKey: 'S'.((string) $position),
            title: $title !== '' ? $title : $this->domainFromUrl($url),
            url: $url !== '' ? $url : null,
            domain: $this->domainFromUrl($url),
            siteName: $this->nullableString($annotation['site_name'] ?? ($source['site_name'] ?? null)),
            snippet: $this->nullableString($annotation['text'] ?? null),
            rank: $position,
            metadata: $annotation,
        );
    }

    /**
     * @param  list<AiVisibilitySourceData>  $sources
     * @return list<AiVisibilitySourceData>
     */
    private function appendUniqueSource(array $sources, AiVisibilitySourceData $source): array
    {
        if ($source->url === null || $source->url === '') {
            $sources[] = $source;

            return $sources;
        }

        foreach ($sources as $existing) {
            if ($existing->url === $source->url) {
                return $sources;
            }
        }

        $sources[] = $source;

        return $sources;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return $string !== '' ? $string : null;
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function domainFromUrl(string $url): ?string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function parsePublishedAt(mixed $value): ?CarbonImmutable
    {
        $string = $this->stringValue($value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (Throwable) {
            return null;
        }
    }

    private function limitText(?string $value, int $maxChars): ?string
    {
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value, 'UTF-8') <= $maxChars) {
            return $value;
        }

        return mb_substr($value, 0, $maxChars, 'UTF-8');
    }
}
