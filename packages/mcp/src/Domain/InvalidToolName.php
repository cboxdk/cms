<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The text is not the name of an MCP tool, or a command's name and version would give a tool name
 * longer than MCP clients take.
 */
#[Internal]
final class InvalidToolName extends InvalidArgumentException
{
    public static function form(string $value): self
    {
        return new self(sprintf('"%s" is not the name of an MCP tool: the command\'s name with hyphens for its dots, then -v and the version, such as entry-create-v1.', $value));
    }

    public static function length(string $value): self
    {
        return new self(sprintf('The MCP tool name "%s" is longer than %d characters, which MCP clients do not take. Give the command a shorter name.', $value, ToolName::MAX_LENGTH));
    }
}
