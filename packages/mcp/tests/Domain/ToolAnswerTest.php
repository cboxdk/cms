<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Domain;

use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use InvalidArgumentException;

/*
 * The MCP surface's answers: a tool result, a tool error, or a JSON-RPC error, as the error
 * catalog's MCP column says.
 */

it('gives a tool result and a tool error as tool results, and the others as JSON-RPC errors', function (): void {
    $result = ToolAnswer::result('{"outcome":"committed"}');
    $error = ToolAnswer::toolError('{"code":"version_conflict"}', 'The content changed.');
    $internal = ToolAnswer::rpcError(McpResponse::InternalError, '{"code":"registry_cache_missing"}', 'Run cms:build.');
    $unknown = ToolAnswer::invalidParams('Tool [x] not found.');

    expect([$result->isToolResult(), $result->response, $result->document])->toBe([true, McpResponse::Result, '{"outcome":"committed"}'])
        ->and([$error->isToolResult(), $error->response, $error->message])->toBe([true, McpResponse::ToolError, 'The content changed.'])
        ->and([$internal->isToolResult(), $internal->rpcCode, $internal->document])->toBe([false, -32603, '{"code":"registry_cache_missing"}'])
        ->and([$unknown->isToolResult(), $unknown->rpcCode, $unknown->document])->toBe([false, ToolAnswer::INVALID_PARAMS, null]);
});

it('refuses a JSON-RPC error for an answer the catalog gives as a tool result', function (McpResponse $response): void {
    expect(static fn (): ToolAnswer => ToolAnswer::rpcError($response, '{}', 'm'))->toThrow(InvalidArgumentException::class, 'is a tool result, not a JSON-RPC error');
})->with([McpResponse::Result, McpResponse::ToolError]);
