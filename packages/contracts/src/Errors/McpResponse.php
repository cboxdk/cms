<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Errors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How the MCP surface answers a tool call that ends with a code of the error catalog (PRD 6.1,
 * GUARDRAILS 2.1).
 *
 * - Result: a normal tool result, as for a dry run, which is no failure.
 * - ToolError: a tool result with isError set, which the agent reads and can act on, by fixing its
 *   input, reading the current state again or trying later.
 * - InternalError: a JSON-RPC error with code -32603, because the installation, not the call, is
 *   at fault, and the agent cannot fix it by changing its call.
 */
#[Experimental]
enum McpResponse: string
{
    case Result = 'result';
    case ToolError = 'tool_error';
    case InternalError = 'internal_error';

    /**
     * The JSON-RPC error code, or null for an answer that is a tool result.
     */
    public function jsonRpcCode(): ?int
    {
        return match ($this) {
            self::Result, self::ToolError => null,
            self::InternalError => -32603,
        };
    }

    /**
     * How the answer is described in the error reference.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Result => 'a tool result',
            self::ToolError => 'a tool result with isError set',
            self::InternalError => 'the JSON-RPC error -32603, Internal error',
        };
    }
}
