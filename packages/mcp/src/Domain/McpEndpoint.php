<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use Cbox\Cms\Mcp\Domain\Dto\ToolCall;
use Cbox\Cms\Mcp\Domain\Dto\ToolListing;

/**
 * What the MCP server of the adapter asks of the MCP surface (GUARDRAILS 1, 2.1): the tools to list
 * and the answer to a tool call. The adapter keeps laravel/mcp to itself and knows the surface only
 * through this port, which McpSurface implements.
 */
#[Internal]
interface McpEndpoint
{
    public function tools(): ToolListing;

    public function call(ToolCall $call): ToolAnswer;
}
