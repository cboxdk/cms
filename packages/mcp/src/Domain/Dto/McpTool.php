<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Mcp\Domain\ToolName;
use InvalidArgumentException;

/**
 * One MCP tool (GUARDRAILS 2.1, PRD 14.5): an action the registry exposes on Surface::Mcp, with its
 * name, title and description, the JSON Schema of its arguments, and the codecs that read them,
 * the CommandCodec of a write or the QueryCodec of a read, exactly one of the two.
 */
#[Internal]
final readonly class McpTool
{
    /**
     * @throws InvalidArgumentException when the tool has both codecs or neither
     */
    public function __construct(
        public ToolName $name,
        public string $title,
        public string $description,
        public JsonSchema $input,
        public ?CommandCodec $command,
        public ?QueryCodec $query,
    ) {
        if (($command instanceof CommandCodec) === ($query instanceof QueryCodec)) {
            throw new InvalidArgumentException(sprintf('The MCP tool %s must run a command or a query, and only one of them.', $name->value));
        }
    }

    /**
     * Whether the tool only reads: it runs a query, never a command.
     */
    public function readsOnly(): bool
    {
        return $this->query instanceof QueryCodec;
    }
}
