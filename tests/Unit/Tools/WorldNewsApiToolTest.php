<?php

declare(strict_types=1);

use Spora\Plugins\WorldNews\Tools\WorldNewsApiTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Build a fresh WorldNewsApiTool with a real config + http-client mock.
 * Returns [$config, $client, $tool] so tests can wire expectations onto
 * $config/$client and call $tool->execute(...). PHPStan-friendly: every test
 * gets a locally-typed $tool without relying on Pest's dynamic $this->.
 *
 * @return array{0: ToolConfigService&Mockery\MockInterface, 1: HttpClientInterface&Mockery\MockInterface, 2: WorldNewsApiTool}
 */
function makeWorldNewsTool(): array
{
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    return [$config, $client, new WorldNewsApiTool($config, $client)];
}

it('returns error if api key is missing', function () {
    [$config, , $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn([]);

    $result = $tool->execute(['q' => 'news'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('is not configured');
});

it('makes correct search request and parses articles', function () {
    [$config, $client, $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'news' => [
            [
                'title' => 'World Event',
                'source' => 'Reuters',
                'publish_date' => '2026-04-23T10:00:00Z',
                'summary' => 'Something happened worldwide.',
                'url' => 'https://reuters.com/article',
                'image' => 'https://reuters.com/image.jpg',
                'language' => 'en',
                'category' => 'news',
            ],
        ],
    ]);

    $client->expects('request')->with('GET', 'https://api.worldnewsapi.com/search-news', Mockery::on(function ($options) {
        return $options['headers']['x-api-key'] === 'wn_123'
            && $options['query']['text'] === 'world news'
            && $options['query']['number'] === 10;
    }))->andReturn($response);

    $result = $tool->execute(['q' => 'world news'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('World Event')
        ->and($result->content)->toContain('Reuters')
        ->and($result->content)->toContain('Something happened worldwide.');
});

it('makes correct top-news request and parses clustered results', function () {
    [$config, $client, $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'top_news' => [
            [
                'news' => [
                    [
                        'title' => 'Top Story',
                        'source' => 'AP',
                        'publish_date' => '2026-04-23T08:00:00Z',
                        'summary' => 'Breaking news.',
                        'url' => 'https://apnews.com/story',
                    ],
                ],
            ],
        ],
    ]);

    $client->allows('request')->with('GET', 'https://api.worldnewsapi.com/top-news', Mockery::any())->andReturn($response);

    $result = $tool->execute(['operation' => 'top-news', 'source-country' => 'us', 'language' => 'en'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Top Story')
        ->and($result->content)->toContain('AP')
        ->and($result->content)->toContain('Breaking news.');
});

it('returns error when search query is empty', function () {
    [$config, , $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    $result = $tool->execute(['q' => ''], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('cannot be empty');
});

it('returns error when top-news missing source-country', function () {
    [$config, , $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    $result = $tool->execute(['operation' => 'top-news'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('source-country is required');
});

it('returns error when top-news missing language (source-country provided)', function () {
    [$config, , $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    $result = $tool->execute(['operation' => 'top-news', 'source-country' => 'us'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('language is required');
});

it('uses http_timeout setting when provided', function () {
    [$config, $client, $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key' => 'wn_123',
        'http_timeout' => 60,
    ]);

    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn(['news' => []]);

    $client->expects('request')->with('GET', Mockery::any(), Mockery::on(function ($options) {
        return ($options['timeout'] ?? null) === 60;
    }))->andReturn($response);

    $result = $tool->execute(['q' => 'foo'], 1);
    expect($result->success)->toBeTrue();
});

it('describes the action for top-news and search operations', function () {
    [$config, , $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    expect($tool->describeAction(['operation' => 'top-news', 'source-country' => 'us']))
        ->toBe("Fetch top news from WorldNewsAPI for country: 'us'");

    expect($tool->describeAction(['q' => 'foo']))
        ->toBe("Search WorldNewsAPI for: 'foo'");
});

it('returns api-key error before validating top-news fields', function () {
    [$config, , $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn([]);

    $result = $tool->execute([
        'operation' => 'top-news',
        'source-country' => 'us',
        'language' => 'en',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('is not configured');
});

it('returns HTTP error ToolResult when the API responds with a 4xx/5xx status (search and top-news)', function () {
    [$config, $client, $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(500);
    $response->allows('getContent')->with(false)->andReturn('internal error');

    $client->allows('request')->with('GET', Mockery::any(), Mockery::any())->andReturn($response);

    $search = $tool->execute(['q' => 'foo'], 1);
    expect($search->success)->toBeFalse()
        ->and($search->content)->toContain('HTTP 500');

    $topNews = $tool->execute([
        'operation' => 'top-news',
        'source-country' => 'us',
        'language' => 'en',
    ], 1);
    expect($topNews->success)->toBeFalse()
        ->and($topNews->content)->toContain('HTTP 500');
});

it('returns exception ToolResult when the HTTP request throws', function () {
    [$config, $client, $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    $client->allows('request')->andThrow(new RuntimeException('boom'));

    $result = $tool->execute(['q' => 'foo'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('WorldNewsAPI request error')
        ->and($result->content)->toContain('boom');
});

it('renders the empty-articles header without rows when the API returns no news', function () {
    [$config, $client, $tool] = makeWorldNewsTool();
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'wn_123']);

    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn(['news' => []]);

    $client->allows('request')->andReturn($response);

    $result = $tool->execute(['q' => 'silent news day'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('No recent news found')
        ->and($result->content)->not->toContain('[1]');
});
