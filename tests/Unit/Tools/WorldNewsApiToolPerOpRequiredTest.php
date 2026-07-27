<?php

declare(strict_types=1);

use Spora\Plugins\WorldNews\Tools\WorldNewsApiTool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;

/**
 * Per-op `required[]` binding tests for WorldNewsApiTool.
 *
 * Note: this tool uses `discriminatorKey: 'operation'` (not the default
 * `'action'`). The bindings below reference op names that match the
 * declared `#[ToolOperation]` names; the orchestrator reads the
 * discriminator key from the first op attribute and threads it through
 * `OperationSchemaFilter::filter()` — so the runtime narrowing works
 * correctly regardless of what name the discriminator uses.
 *
 * Reads attribute arguments via reflection; does NOT instantiate. Once
 * spora-core ships the `bool|array $required` signature AND the plugin
 * bumps its dep, replace with
 * `ToolParameterSchemaBuilder::build(WorldNewsApiTool::class, 'operation')`.
 */
function worldNewsToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(WorldNewsApiTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . WorldNewsApiTool::class);
}

function worldNewsToolOperationNames(): array
{
    $reflection = new ReflectionClass(WorldNewsApiTool::class);
    $names = [];
    foreach ($reflection->getAttributes(ToolOperation::class) as $attr) {
        $args = $attr->getArguments();
        if (isset($args['name'])) {
            $names[] = $args['name'];
        }
    }

    return $names;
}

it('uses operation as discriminator key', function () {
    // Defensive: lock the discriminated key so a future refactor doesn't
    // silently switch the descriptor and let `required` bindings dangle.
    $ops = worldNewsToolOperationNames();
    expect($ops)->toContain('search', 'top-news');
});

it('binds q to search only', function () {
    expect(worldNewsToolParameterArgs('q')['required'])->toBe(['search']);
});

it('binds source-country and language to top-news', function () {
    expect(worldNewsToolParameterArgs('source-country')['required'])->toBe(['top-news']);
    expect(worldNewsToolParameterArgs('language')['required'])->toBe(['top-news']);
});

it('keeps filters at required: false', function () {
    expect(worldNewsToolParameterArgs('category')['required'])->toBeFalse();
    expect(worldNewsToolParameterArgs('earliest-publish-date')['required'])->toBeFalse();
    expect(worldNewsToolParameterArgs('latest-publish-date')['required'])->toBeFalse();
    expect(worldNewsToolParameterArgs('number')['required'])->toBeFalse();
    expect(worldNewsToolParameterArgs('offset')['required'])->toBeFalse();
});