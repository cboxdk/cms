<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Adapter;

use Cbox\Cms\Mcp\Adapter\CallToolMethod;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use Illuminate\Http\Request;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;

/*
 * tools/call apart from HTTP: a tool result whose document is a JSON object is also its
 * structured content, and one whose document is no object is text alone.
 */

/**
 * The result tools/call gives for a call answered with $answer.
 *
 * @return array<array-key, mixed>
 */
function toolCallResult(ToolAnswer $answer): array
{
    $body = '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"probe-cards-v1","arguments":{}}}';
    $method = new CallToolMethod(new AnsweringEndpoint($answer), Request::create('/mcp', 'POST', content: $body));
    $context = new ServerContext([], [], new Implementation('cms', '1'), '', 50, 15, [], [], []);
    $response = $method->handle(new JsonRpcRequest(7, 'tools/call', ['name' => 'probe-cards-v1', 'arguments' => []]), $context)->toArray();
    $result = $response['result'] ?? null;

    return is_array($result) ? $result : [];
}

it('gives a document that is a JSON object as the structured content too', function (): void {
    $result = toolCallResult(ToolAnswer::result('{"cards":[]}'));

    expect($result['content'] ?? null)->toBe([['type' => 'text', 'text' => '{"cards":[]}']])
        ->and($result['structuredContent'] ?? null)->toEqual((object) ['cards' => []])
        ->and($result['isError'] ?? null)->toBeFalse();
});

it('gives a document that is no JSON object as text alone', function (): void {
    $result = toolCallResult(ToolAnswer::result('["a","b"]'));

    expect($result['content'] ?? null)->toBe([['type' => 'text', 'text' => '["a","b"]']])
        ->and(array_key_exists('structuredContent', $result))->toBeFalse();
});
