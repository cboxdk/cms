<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\FieldPath;
use RuntimeException;
use Throwable;

/**
 * The MCP surface refused a tool call before the kernel ran it (GUARDRAILS 2.1), so nothing was
 * read or committed and no idempotency key was claimed. Either the refusal has a code of the error
 * catalog, with the path of the value in the arguments it names, such as json_invalid at
 * `envelope.wait_level` or a registry cache that cannot be read, and the surface answers as the
 * catalog's MCP column says; or the call names no tool the surface offers, an error of the
 * protocol, which is the JSON-RPC error -32602 without a catalog code.
 */
#[Internal]
final class ToolCallRefused extends RuntimeException
{
    private function __construct(
        public readonly ?ErrorCode $errorCode,
        public readonly ?FieldPath $path,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function catalog(ErrorCode $code, ?FieldPath $path, string $message, ?Throwable $previous = null): self
    {
        return new self($code, $path, $message, $previous);
    }

    public static function unknownTool(string $name): self
    {
        return new self(null, null, sprintf('Tool [%s] not found. List the tools with tools/list; each is named after its command or query, such as entry-create-v1.', $name));
    }
}
