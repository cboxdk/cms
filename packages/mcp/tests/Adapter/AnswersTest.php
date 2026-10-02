<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Adapter;

use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Mcp\Adapter\Answers;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use JsonException;
use Throwable;

/*
 * The MCP adapter's JSON: slashes and characters beyond ASCII are written as they are, a whole
 * float keeps its fraction, a value with no JSON form throws, and a document deeper than
 * json_decode's depth of 512 is refused, also as a JSON-RPC error's data.
 */

/**
 * The refusal $read throws, or null.
 *
 * @param  callable(): mixed  $read
 */
function answerRefusal(callable $read): ?Throwable
{
    try {
        $read();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

it('writes slashes and characters beyond ASCII as they are and keeps the fraction of a whole float', function (): void {
    expect(Answers::json(['path' => '/v1/ø', 'ratio' => 1.0]))->toBe('{"path":"/v1/ø","ratio":1.0}');
});

it('throws for a value that has no JSON form', function (): void {
    expect(answerRefusal(static fn (): string => Answers::json(NAN)))->toBeInstanceOf(JsonException::class);
});

it('reads a document of 511 nested arrays, the most within a depth of 512, and refuses one more', function (): void {
    $deepest = str_repeat('[', 511).str_repeat(']', 511);
    $deeper = str_repeat('[', 512).str_repeat(']', 512);
    $answer = ToolAnswer::rpcError(McpResponse::InternalError, $deeper, 'failed');

    expect(Answers::document($deepest))->toBeArray()
        ->and(answerRefusal(static fn (): mixed => Answers::document($deeper)))->toBeInstanceOf(JsonException::class)
        ->and(answerRefusal(static fn (): mixed => Answers::exception($answer, 1)))->toBeInstanceOf(JsonException::class)
        ->and(Answers::exception(ToolAnswer::rpcError(McpResponse::InternalError, $deepest, 'failed'), 1)->getMessage())->toBe('failed');
});
