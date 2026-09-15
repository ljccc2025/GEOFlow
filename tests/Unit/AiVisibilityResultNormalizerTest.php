<?php

namespace Tests\Unit;

use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\AiVisibilityResultNormalizer;
use PHPUnit\Framework\TestCase;

class AiVisibilityResultNormalizerTest extends TestCase
{
    public function test_it_normalizes_doubao_ark_responses_annotations(): void
    {
        $result = (new AiVisibilityResultNormalizer)->normalizeArkResponses([
            'id' => 'resp_123',
            'output' => [
                [
                    'type' => 'web_search_call',
                    'id' => 'ws_123',
                    'status' => 'completed',
                    'action' => ['query' => 'GEOFlow'],
                ],
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'GEOFlow 是一个面向 AI 可见性的内容系统。',
                            'annotations' => [
                                [
                                    'type' => 'url_citation',
                                    'title' => 'GEOFlow Docs',
                                    'url' => 'https://www.example.com/geoflow',
                                    'text' => 'GEOFlow Docs',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'tool_usage' => ['web_search' => 1],
            ],
        ], ['payload' => ['model' => 'doubao-seed']], 'doubao-seed', 120);

        $this->assertSame('GEOFlow 是一个面向 AI 可见性的内容系统。', $result->answerText);
        $this->assertSame('doubao-seed', $result->modelId);
        $this->assertSame(120, $result->latencyMs);
        $this->assertCount(1, $result->sources);
        $this->assertSame('native_annotation', $result->sources[0]->sourceType);
        $this->assertSame('example.com', $result->sources[0]->domain);
        $this->assertSame('ws_123', $result->metadata['web_search_calls'][0]['id']);
    }

    public function test_it_normalizes_doubao_search_custom_results(): void
    {
        $result = (new AiVisibilityResultNormalizer)->normalizeDoubaoSearchCustom([
            'LogId' => 'log_123',
            'Result' => [
                'TimeCost' => 122,
                'WebResults' => [
                    [
                        'Title' => 'AI Visibility Guide',
                        'SiteName' => 'Example',
                        'Url' => 'https://example.com/ai-visibility',
                        'Snippet' => 'AI 可见性指南',
                        'Summary' => '介绍如何优化 AI 可见性。',
                        'Content' => '正文内容',
                        'PublishTime' => '2026-07-01T10:00:00+08:00',
                        'RankScore' => 0.98,
                        'AuthInfoLevel' => 'high',
                    ],
                ],
            ],
        ], ['payload' => ['Query' => 'AI visibility']], 88);

        $this->assertSame('', $result->answerText);
        $this->assertSame('doubao_search_custom', $result->providerType);
        $this->assertSame(88, $result->latencyMs);
        $this->assertSame('log_123', $result->metadata['log_id']);
        $this->assertCount(1, $result->sources);
        $this->assertSame('web_search_result', $result->sources[0]->sourceType);
        $this->assertSame('example.com', $result->sources[0]->domain);
        $this->assertSame(0.98, $result->sources[0]->rankScore);
    }

    public function test_it_normalizes_perplexity_chat_completions_with_citations(): void
    {
        $result = (new AiVisibilityResultNormalizer)->normalizePerplexity([
            'id' => 'pplx-123',
            'model' => 'sonar',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => '我们推荐 A 公司。'],
                'finish_reason' => 'stop',
            ]],
            'citations' => ['https://example.com/a'],
            'search_results' => [[
                'title' => 'A 公司官网',
                'url' => 'https://example.com/a',
                'date' => '2026-01-01',
            ]],
            'usage' => ['total_tokens' => 42],
        ], ['model' => 'sonar'], 123);

        $this->assertSame(AiVisibilityRun::PROVIDER_PERPLEXITY_SEARCH, $result->providerType);
        $this->assertSame('我们推荐 A 公司。', $result->answerText);
        $this->assertCount(1, $result->sources);
        $this->assertSame('https://example.com/a', $result->sources[0]->url);
        $this->assertSame(123, $result->latencyMs);
    }

    public function test_it_deduplicates_perplexity_citations_and_search_results(): void
    {
        $result = (new AiVisibilityResultNormalizer)->normalizePerplexity([
            'choices' => [['message' => ['content' => '答案']]],
            'citations' => ['https://example.com/a'],
            'search_results' => [['title' => 'A', 'url' => 'https://example.com/a']],
        ], [], 10);

        $this->assertCount(1, $result->sources, '同一 URL 出现在 citations 与 search_results 时只应保留一条信源。');
    }

    public function test_it_returns_empty_answer_when_perplexity_payload_has_no_content(): void
    {
        $result = (new AiVisibilityResultNormalizer)->normalizePerplexity([], [], 5);

        $this->assertSame('', $result->answerText);
        $this->assertSame([], $result->sources);
    }

    public function test_it_normalizes_openai_web_search_annotations(): void
    {
        $result = (new AiVisibilityResultNormalizer)->normalizeOpenAiWebSearch([
            'id' => 'resp_openai_1',
            'output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['query' => 'earplug factory china']],
                ['type' => 'message', 'content' => [[
                    'type' => 'output_text',
                    'text' => 'We recommend A Corp.',
                    'annotations' => [['type' => 'url_citation', 'title' => 'A Corp', 'url' => 'https://example.com/a']],
                ]]],
            ],
        ], ['model' => 'gpt-4o'], 88);

        $this->assertSame(AiVisibilityRun::PROVIDER_OPENAI_WEB_SEARCH, $result->providerType);
        $this->assertSame('We recommend A Corp.', $result->answerText);
        $this->assertCount(1, $result->sources);
        $this->assertSame(88, $result->latencyMs);
    }

    public function test_ark_and_openai_share_the_same_parser_but_differ_in_provider_identity(): void
    {
        $normalizer = new AiVisibilityResultNormalizer;
        $payload = ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'X']]]]];

        $ark = $normalizer->normalizeArkResponses($payload, [], 'ark-model', 1);
        $openai = $normalizer->normalizeOpenAiWebSearch($payload, [], 1);

        $this->assertSame('X', $ark->answerText);
        $this->assertSame('X', $openai->answerText);
        $this->assertNotSame($ark->providerType, $openai->providerType);
    }
}
