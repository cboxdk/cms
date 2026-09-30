<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Http\Credentials\Boundary\BearerCredential;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use Cbox\Cms\Mcp\Domain\Dto\ToolCall;
use Cbox\Cms\Mcp\Domain\McpEndpoint;
use Illuminate\Http\Request;
use JsonException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Override;
use stdClass;

/**
 * tools/call (GUARDRAILS 2.1): hands the call to the MCP surface as a ToolCall and gives its answer
 * back, a tool result, with isError set for a tool error, or a JSON-RPC error.
 *
 * - The credential is the HTTP request's Bearer token (PRD 5.16), never an argument.
 * - The arguments are the JSON text of `params.arguments` as the agent sent them, taken from the
 *   request body: laravel/mcp decodes the message into PHP arrays, where an empty object and an
 *   empty list are the same, so the codecs would read `{}` as `[]`. A call without arguments has
 *   the empty object. A body that is not the message, which only a transport other than HTTP
 *   gives, falls back to laravel/mcp's decoded arguments.
 * - A tool result carries the document, the receipt, the read's result or the problem details, as
 *   its text content and as its structured content.
 */
#[Internal]
final readonly class CallToolMethod implements Method
{
    public function __construct(
        private McpEndpoint $endpoint,
        private Request $http,
    ) {}

    /**
     * @throws JsonRpcException
     */
    #[Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $name = $request->get('name');

        if (! is_string($name)) {
            throw new JsonRpcException('Missing [name] parameter.', ToolAnswer::INVALID_PARAMS, $request->id);
        }

        $answer = $this->endpoint->call(new ToolCall($name, $this->arguments($request), BearerCredential::of($this->http)));

        if (! $answer->isToolResult()) {
            throw Answers::exception($answer, $request->id);
        }

        $text = $answer->document ?? $answer->message;
        $result = [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $answer->response === McpResponse::ToolError,
        ];
        $structured = $answer->document === null ? null : Answers::document($answer->document);

        if ($structured instanceof stdClass) {
            $result['structuredContent'] = $structured;
        }

        return JsonRpcResponse::result($request->id, $result);
    }

    /**
     * The JSON text of the call's arguments.
     */
    private function arguments(JsonRpcRequest $request): string
    {
        try {
            $message = json_decode($this->http->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $message = null;
        }

        $params = $message instanceof stdClass && ($message->id ?? null) === $request->id ? ($message->params ?? null) : null;
        $arguments = $params instanceof stdClass ? ($params->arguments ?? null) : ($request->params['arguments'] ?? null);

        return $arguments === null ? '{}' : Answers::json($arguments);
    }
}
