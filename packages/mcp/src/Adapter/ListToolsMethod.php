<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Mcp\Domain\Dto\McpTool;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use Cbox\Cms\Mcp\Domain\McpEndpoint;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Pagination\CursorPaginator;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Override;

/**
 * tools/list (GUARDRAILS 2.1): the tools of the MCP surface, each with its name, title, description,
 * input schema (the generated JSON forms it reads) and whether it only reads, paged by laravel/mcp's
 * cursor. When the tools cannot be listed, such as when the registry cache cannot be read, the
 * answer is the JSON-RPC error of the surface's answer, with the problem details as its data.
 */
#[Internal]
final readonly class ListToolsMethod implements Method
{
    public function __construct(private McpEndpoint $endpoint) {}

    /**
     * @throws JsonRpcException
     */
    #[Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $listing = $this->endpoint->tools();

        if ($listing->failure instanceof ToolAnswer) {
            throw Answers::exception($listing->failure, $request->id);
        }

        $perPage = $request->get('per_page');
        $paginator = new CursorPaginator(
            items: collect(array_map($this->describe(...), $listing->tools)),
            perPage: $context->perPage(is_int($perPage) ? $perPage : null),
            cursor: $request->cursor(),
        );

        return JsonRpcResponse::result($request->id, $paginator->paginate('tools'));
    }

    /**
     * The tool as tools/list gives it: an object of the name, title, description, input schema and
     * annotations, as the paginator's item.
     */
    private function describe(McpTool $tool): mixed
    {
        return [
            'name' => $tool->name->value,
            'title' => $tool->title,
            'description' => $tool->description,
            'inputSchema' => Answers::document($tool->input->json),
            'annotations' => [
                'title' => $tool->title,
                'readOnlyHint' => $tool->readsOnly(),
            ],
        ];
    }
}
