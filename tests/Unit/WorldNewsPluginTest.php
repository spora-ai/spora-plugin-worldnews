<?php

declare(strict_types=1);

use Spora\Plugins\WorldNews\Tools\WorldNewsApiTool;
use Spora\Plugins\WorldNews\WorldNewsPlugin;

it('returns plugin name', function () {
    $plugin = new WorldNewsPlugin();
    expect($plugin->getName())->toBe('WorldNews');
});

it('contributes the WorldNewsApiTool', function () {
    $plugin = new WorldNewsPlugin();
    expect($plugin->tools())->toBe([WorldNewsApiTool::class]);
});
