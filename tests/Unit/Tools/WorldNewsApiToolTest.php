<?php

declare(strict_types=1);

use Spora\Plugins\WorldNews\Tools\WorldNewsApiTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

beforeEach(function () {
    $this->config = Mockery::mock(ToolConfigService::class);
    $this->client = Mockery::mock(HttpClientInterface::class);
    $this->tool = new WorldNewsApiTool($this->config, $this->client);
});

it('returns error if api key is missing', function () {
    $this->config->allows('getEffectiveSettings')->andReturn([]);

    $result = $this->tool->execute(['q' => 'news'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('is not configured');
});

it('makes correct search request and parses articles', function () {
    $this->config->allows('getEffectiveSettings')->andReturn(['core.worldnewsapi.api_key' => 'wn_123']);

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

    $this->client->expects('request')->with('GET', 'https://api.worldnewsapi.com/search-news', Mockery::on(function ($options) {
        return $options['headers']['x-api-key'] === 'wn_123'
            && $options['query']['text'] === 'world news'
            && $options['query']['number'] === 10;
    }))->andReturn($response);

    $result = $this->tool->execute(['q' => 'world news'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('World Event')
        ->and($result->content)->toContain('Reuters')
        ->and($result->content)->toContain('Something happened worldwide.');
});

it('makes correct top-news request and parses clustered results', function () {
    $this->config->allows('getEffectiveSettings')->andReturn(['core.worldnewsapi.api_key' => 'wn_123']);

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

    $this->client->allows('request')->with('GET', 'https://api.worldnewsapi.com/top-news', Mockery::any())->andReturn($response);

    $result = $this->tool->execute(['operation' => 'top-news', 'source-country' => 'us', 'language' => 'en'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Top Story')
        ->and($result->content)->toContain('AP')
        ->and($result->content)->toContain('Breaking news.');
});

it('returns error when search query is empty', function () {
    $this->config->allows('getEffectiveSettings')->andReturn(['core.worldnewsapi.api_key' => 'wn_123']);

    $result = $this->tool->execute(['q' => ''], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('cannot be empty');
});

it('returns error when top-news missing source-country', function () {
    $this->config->allows('getEffectiveSettings')->andReturn(['core.worldnewsapi.api_key' => 'wn_123']);

    $result = $this->tool->execute(['operation' => 'top-news'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('source-country is required');
});
