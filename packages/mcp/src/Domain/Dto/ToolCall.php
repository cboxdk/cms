<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\TransportCredential;

/**
 * One call of an MCP tool as the transport received it (GUARDRAILS 2.1): the tool's name, its
 * arguments as the JSON text of the object the agent sent, `{}` when it sent none, and the
 * credential the transport carried, or null when it carried none.
 */
#[Internal]
final readonly class ToolCall
{
    public function __construct(
        public string $name,
        public string $arguments,
        public ?TransportCredential $credential,
    ) {}
}
