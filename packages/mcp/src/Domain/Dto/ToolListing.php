<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The tools the MCP surface offers, or why it cannot list them: a JSON-RPC error, such as a
 * registry cache that cannot be read, with the catalog's code in its problem details.
 */
#[Internal]
final readonly class ToolListing
{
    /**
     * @param  list<McpTool>  $tools
     */
    public function __construct(
        public array $tools,
        public ?ToolAnswer $failure = null,
    ) {}
}
