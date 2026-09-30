<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\McpResponse;
use InvalidArgumentException;

/**
 * The MCP surface's answer to a tool call or a listing (GUARDRAILS 2.1, PRD 6.1), as the error
 * catalog's MCP column says to give it (McpResponse):
 *
 * - a tool result: `document` is the JSON of the receipt or of the read's result;
 * - a tool result with isError set: `document` is the problem details (problem.v1.json) with every
 *   catalog code and path;
 * - a JSON-RPC error: `rpcCode`, such as -32603 for an error the installation, not the call, is at
 *   fault for, with `message`, and the problem details as `document` when the error has a catalog
 *   code; or -32602 for a call that names no tool the surface offers, which has none.
 */
#[Internal]
final readonly class ToolAnswer
{
    /** The JSON-RPC code of a call whose parameters name no tool or are not an object. */
    public const int INVALID_PARAMS = -32602;

    private function __construct(
        public McpResponse $response,
        public ?string $document,
        public string $message,
        public ?int $rpcCode,
    ) {}

    public static function result(string $document): self
    {
        return new self(McpResponse::Result, $document, '', null);
    }

    public static function toolError(string $problem, string $message): self
    {
        return new self(McpResponse::ToolError, $problem, $message, null);
    }

    /**
     * A JSON-RPC error for a catalog code whose MCP answer is one (McpResponse::jsonRpcCode()).
     *
     * @throws InvalidArgumentException when the answer is a tool result
     */
    public static function rpcError(McpResponse $response, string $problem, string $message): self
    {
        return new self($response, $problem, $message, $response->jsonRpcCode() ?? throw new InvalidArgumentException(sprintf('The MCP answer %s is a tool result, not a JSON-RPC error.', $response->value)));
    }

    /**
     * The JSON-RPC error -32602 for a call that names no tool the surface offers: an error of the
     * protocol, which has no code in the error catalog.
     */
    public static function invalidParams(string $message): self
    {
        return new self(McpResponse::InternalError, null, $message, self::INVALID_PARAMS);
    }

    /**
     * Whether the answer is a tool result, with or without isError, rather than a JSON-RPC error.
     */
    public function isToolResult(): bool
    {
        return $this->rpcCode === null;
    }
}
