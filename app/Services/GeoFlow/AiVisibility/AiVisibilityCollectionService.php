<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Data\Ai\SystemAiIdentity;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use RuntimeException;

final class AiVisibilityCollectionService
{
    public function __construct(
        private readonly AiVisibilityService $visibility,
        private readonly AiVisibilityConfigurationResolver $configuration,
    ) {}

    /**
     * @return array<string, AiVisibilityRun>
     */
    public function collect(SystemAiIdentity $identity, string $keyword): array
    {
        $identity->assertCanCollectVisibility();
        $keyword = trim($keyword);
        if ($keyword === '') {
            throw new RuntimeException('AI 可见性关键词为空');
        }

        $runs = [];

        $perplexity = $this->configuration->perplexityProvider($identity);
        if ($perplexity instanceof AiSourceProvider) {
            $runs['perplexity_run'] = $this->visibility->runPerplexitySearch($perplexity, $keyword);
        }

        $openAi = $this->configuration->openAiProvider($identity);
        if ($openAi instanceof AiSourceProvider) {
            $runs['openai_run'] = $this->visibility->runOpenAiWebSearch($openAi, $keyword);
        }

        // 两个海外引擎都未配置时，回落既有国内链路（豆包 / DeepSeek），保持既有部署行为不变
        if ($runs === []) {
            return $this->collectDomestic($identity, $keyword);
        }

        return $runs;
    }

    /**
     * 既有的豆包 / DeepSeek 采集链路，原样抽出为私有方法，行为不变。
     *
     * @return array<string, AiVisibilityRun>
     */
    private function collectDomestic(SystemAiIdentity $identity, string $keyword): array
    {
        $provider = $this->configuration->searchProvider($identity);
        $deepSeek = $this->configuration->deepSeekModel($identity);
        if ($provider instanceof AiSourceProvider && $deepSeek instanceof AiModel) {
            return $this->visibility->runDoubaoSearchThenDeepSeekAnalysis(
                $identity,
                $provider,
                $deepSeek,
                $keyword,
            );
        }

        $ark = $this->configuration->arkModel($identity);
        if ($ark instanceof AiModel) {
            return [
                'ark_run' => $this->visibility->runDoubaoArkResponses($identity, $ark, $keyword),
            ];
        }

        if ($provider instanceof AiSourceProvider) {
            return [
                'search_run' => $this->visibility->runDoubaoSearchCustom($provider, $keyword),
            ];
        }

        if ($deepSeek instanceof AiModel) {
            return [
                'analysis_run' => $this->visibility->runDeepSeekAnalysis(
                    $identity,
                    $deepSeek,
                    $keyword,
                    sprintf('请分析关键词「%s」的 GEO/AI 可见性，并给出可执行建议。', $keyword),
                ),
            ];
        }

        throw new RuntimeException('没有可用的 AI 可见性模型或搜索源');
    }
}
