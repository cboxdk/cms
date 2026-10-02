<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Adapter;

use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use Cbox\Cms\Mcp\Domain\Dto\ToolCall;
use Cbox\Cms\Mcp\Domain\Dto\ToolListing;
use Cbox\Cms\Mcp\Domain\McpEndpoint;
use Override;

/**
 * An MCP surface that answers every call with one answer and records the calls.
 */
final class AnsweringEndpoint implements McpEndpoint
{
    /** @var list<ToolCall> */
    public array $calls = [];

    public function __construct(private readonly ToolAnswer $answer) {}

    #[Override]
    public function tools(): ToolListing
    {
        return new ToolListing([]);
    }

    #[Override]
    public function call(ToolCall $call): ToolAnswer
    {
        $this->calls[] = $call;

        return $this->answer;
    }
}
