<?php

declare(strict_types=1);

namespace Spora\Plugins\WorldNews;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\WorldNews\Tools\WorldNewsApiTool;

/**
 * World News API headlines and search for Spora agents.
 */
final class WorldNewsPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'WorldNews';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            WorldNewsApiTool::class,
        ];
    }
}
