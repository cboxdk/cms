<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Mcp\Domain\Dto\McpTool;
use Cbox\Cms\Mcp\Domain\Dto\UndescribedTool;
use InvalidArgumentException;

/**
 * The MCP tools of the installation (GUARDRAILS 2.1): one per action the compiled registry exposes
 * on Surface::Mcp whose command or query a codec reads, sorted by name, and the actions exposed on
 * MCP that no codec reads, which the surface cannot describe and so does not offer (undescribed()).
 * The Boundary ToolCompiler builds it from the registry cms:build compiles and the codecs.
 */
#[Internal]
final readonly class McpTools
{
    /** @var list<McpTool> sorted by name */
    public array $tools;

    /**
     * @param  list<McpTool>  $tools
     * @param  list<UndescribedTool>  $undescribed
     *
     * @throws InvalidArgumentException when two tools have one name
     */
    public function __construct(array $tools, public array $undescribed = [])
    {
        $byName = [];

        foreach ($tools as $tool) {
            if (isset($byName[$tool->name->value])) {
                throw new InvalidArgumentException(sprintf('Two MCP tools are named %s.', $tool->name->value));
            }

            $byName[$tool->name->value] = $tool;
        }

        ksort($byName, SORT_STRING);
        $this->tools = array_values($byName);
    }

    /**
     * The tool with the name, or null when the surface offers none by it.
     */
    public function find(string $name): ?McpTool
    {
        return array_find($this->tools, static fn (McpTool $tool): bool => $tool->name->value === $name);
    }
}
