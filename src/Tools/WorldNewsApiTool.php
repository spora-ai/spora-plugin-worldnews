<?php

declare(strict_types=1);

namespace Spora\Plugins\WorldNews\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Searches world news from thousands of sources via WorldNewsAPI.
 * Supports text search, category filters, date ranges, and top news by country.
 */
#[Tool(
    name: 'worldnews_search',
    description: 'Search world news from thousands of sources with no time delay. Use this to find out what is happening right now regarding a specific topic.',
    displayName: 'World News Search',
    category: 'research',
)]
// Historical wart: this tool has always used the LLM-facing key 'operation'
// instead of the default 'action'. The discriminatorKey on every operation
// declares that explicitly so the auto-synthesized property name matches what
// the LLM is told to send (and what dispatch reads).
#[ToolOperation(name: 'search', description: 'Search news by text, country, language, or semantic entities', enabledByDefault: true, requiresApprovalByDefault: false, discriminatorKey: 'operation')]
#[ToolOperation(name: 'top-news', description: 'Get top trending news for a specific country', enabledByDefault: true, requiresApprovalByDefault: false, discriminatorKey: 'operation')]
#[ToolSetting(
    key: 'core.worldnewsapi.api_key',
    label: 'WorldNewsAPI Key',
    type: 'password',
    description: 'API key for worldnewsapi.com',
    required: true,
)]
#[ToolSetting(
    key: 'core.worldnewsapi.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
)]
#[ToolParameter(name: 'q', type: 'string', description: 'Keywords or phrases to search for (required for search).', required: false)]
#[ToolParameter(name: 'source-country', type: 'string', description: 'ISO country code, e.g. "us" or "de" (required for top-news).', required: false)]
#[ToolParameter(name: 'language', type: 'string', description: 'ISO 2-letter language code, e.g. "en" or "de" (required for top-news).', required: false)]
#[ToolParameter(name: 'category', type: 'string', description: 'News category, e.g. "politics", "sports", "technology".', required: false)]
#[ToolParameter(name: 'earliest-publish-date', type: 'string', description: 'Earliest publish date (ISO 8601 format, e.g. 2026-04-01).', required: false)]
#[ToolParameter(name: 'latest-publish-date', type: 'string', description: 'Latest publish date (ISO 8601 format, e.g. 2026-04-23).', required: false)]
#[ToolParameter(name: 'entities', type: 'array', description: 'Semantic entities to search for: people, organizations, locations.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'number', type: 'integer', description: 'Maximum number of results (1-100, default 10).', required: false)]
#[ToolParameter(name: 'offset', type: 'integer', description: 'Pagination offset.', required: false)]
final class WorldNewsApiTool extends AbstractTool
{
    private const BASE_URL = 'https://api.worldnewsapi.com';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.worldnewsapi.http_timeout']) && (int) $settings['core.worldnewsapi.http_timeout'] > 0) {
            return (int) $settings['core.worldnewsapi.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'top-news' => $this->topNews($arguments, $agentId, $userId),
            default => $this->search($arguments, $agentId, $userId),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'top-news' => "Fetch top news from WorldNewsAPI for country: '" . ($arguments['source-country'] ?? 'unknown') . "'",
            default => "Search WorldNewsAPI for: '" . ($arguments['q'] ?? '') . "'",
        };
    }

    public function search(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.worldnewsapi.api_key'] ?? '';

        $validationFailure = $this->validateSearchRequest($apiKey, $query);
        if ($validationFailure !== null) {
            return $validationFailure;
        }

        return $this->executeSearch($arguments, $apiKey, $settings, $query);
    }

    public function topNews(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $country = trim((string) ($arguments['source-country'] ?? ''));
        $language = trim((string) ($arguments['language'] ?? ''));
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.worldnewsapi.api_key'] ?? '';

        $validationFailure = $this->validateTopNewsRequest($apiKey, $country, $language);
        if ($validationFailure !== null) {
            return $validationFailure;
        }

        return $this->executeTopNews($apiKey, $settings, $country, $language);
    }

    private function validateSearchRequest(string $apiKey, string $query): ?ToolResult
    {
        $apiKeyFailure = $this->validateApiKey($apiKey);
        if ($apiKeyFailure !== null) {
            return $apiKeyFailure;
        }
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }
        return null;
    }

    private function validateTopNewsRequest(string $apiKey, string $country, string $language): ?ToolResult
    {
        $apiKeyFailure = $this->validateApiKey($apiKey);
        if ($apiKeyFailure !== null) {
            return $apiKeyFailure;
        }
        if ($country === '' || $language === '') {
            $field = $country === '' ? 'source-country' : 'language';
            return new ToolResult(false, "{$field} is required for top-news.");
        }
        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $settings
     */
    private function executeSearch(array $arguments, string $apiKey, array $settings, string $query): ToolResult
    {
        $queryParams = [
            'text' => $query,
            'number' => min(100, (int) ($arguments['number'] ?? 10)),
            'offset' => (int) ($arguments['offset'] ?? 0),
            'source-country' => $arguments['source-country'] ?? null,
            'language' => $arguments['language'] ?? null,
            'category' => $arguments['category'] ?? null,
            'earliest-publish-date' => $arguments['earliest-publish-date'] ?? null,
            'latest-publish-date' => $arguments['latest-publish-date'] ?? null,
            'entities' => isset($arguments['entities']) ? implode(',', $arguments['entities']) : null,
        ];

        $data = $this->performRequest(self::BASE_URL . '/search-news', $queryParams, $apiKey, $settings);
        if ($data instanceof ToolResult) {
            return $data;
        }

        return $this->formatNewsArticles("News Results for '{$query}':\n\n", $data['news'] ?? [], 'No recent news found for this topic.');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function executeTopNews(string $apiKey, array $settings, string $country, string $language): ToolResult
    {
        $queryParams = [
            'source-country' => $country,
            'language' => $language,
        ];

        $data = $this->performRequest(self::BASE_URL . '/top-news', $queryParams, $apiKey, $settings);
        if ($data instanceof ToolResult) {
            return $data;
        }

        $articles = [];
        foreach (($data['top_news'] ?? []) as $group) {
            foreach ($group['news'] ?? [] as $article) {
                $articles[] = $article;
            }
        }
        return $this->formatNewsArticles("Top News:\n\n", $articles, 'No top news available.');
    }

    private function validateApiKey(string $apiKey): ?ToolResult
    {
        if (empty($apiKey)) {
            return new ToolResult(false, 'WorldNewsAPI key is not configured for this agent.');
        }
        return null;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|ToolResult Decoded JSON on success, or a failure ToolResult.
     */
    private function performRequest(string $url, array $queryParams, string $apiKey, array $settings): array|ToolResult
    {
        $timeout = $this->effectiveTimeout($settings);
        try {
            $this->logger?->debug('WorldNewsApiTool: HTTP request', [
                'method' => 'GET',
                'url' => $url,
                'headers' => ['x-api-key' => '***'],
                'query' => $queryParams,
                'timeout' => $timeout,
            ]);

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['x-api-key' => $apiKey],
                'query' => $queryParams,
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('WorldNewsApiTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => $url,
            ]);

            if ($statusCode >= 400) {
                $this->logger?->error('WorldNewsAPI error', ['status' => $statusCode, 'body' => $response->getContent(false)]);
                return new ToolResult(false, "WorldNewsAPI request failed with HTTP {$statusCode}");
            }

            return $response->toArray(false);
        } catch (Throwable $e) {
            $this->logger?->error('WorldNewsAPI exception', ['exception' => $e]);
            return new ToolResult(false, 'WorldNewsAPI request error: ' . $e->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>> $articles
     */
    private function formatNewsArticles(string $header, array $articles, string $emptyMessage): ToolResult
    {
        if ($articles === []) {
            return new ToolResult(true, $header . $emptyMessage . "\n");
        }

        $output = $header;
        foreach ($articles as $i => $article) {
            $num = $i + 1;
            $title = $article['title'] ?? 'No Title';
            $source = $article['source'] ?? 'Unknown Source';
            $publishDate = $article['publish_date'] ?? 'Unknown Date';
            $summary = $article['summary'] ?? 'No description available';
            $url = $article['url'] ?? '#';

            $output .= "[{$num}] {$title} ({$source} - {$publishDate})\n";
            $output .= "{$summary}\n";
            $output .= "URL: {$url}\n\n";
        }

        return new ToolResult(true, $output);
    }
}
