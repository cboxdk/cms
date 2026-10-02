<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Mcp\Boundary\KernelSchemas;
use Cbox\Cms\Mcp\Boundary\ToolCompiler;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Domain\ToolName;
use Cbox\Cms\Mcp\Tests\Support\McpClient;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * Reads on MCP with tool errors (GUARDRAILS 2.1, PRD 2.31): the tools compiled from the registry
 * with the installation's codecs, and a tools/call of the query's tool on the workbench's
 * endpoint, with the query's document as its argument and an agent's credential as the Bearer
 * token. An answered read is a tool result of the result's document, its text content the JSON
 * the result's codec wrote (McpAnswer::document() holds the structured content to it); a rejection is the catalog's
 * McpResponse with the problem, the paths of a refused document below `query`.
 */
final readonly class McpQueryProfile implements QuerySurfaceProfile
{
    #[Override]
    public function surface(): Surface
    {
        return Surface::Mcp;
    }

    #[Override]
    public function prepare(TestCase $test, CompiledRegistry $registry): void
    {
        app()->instance(McpTools::class, new ToolCompiler()->compile($registry, app(CommandCodecs::class), app(QueryCodecs::class), KernelSchemas::envelope()));
    }

    #[Override]
    public function credential(QueryContractKernel $kernel): TransportCredential
    {
        return $kernel->world->agentCredential();
    }

    #[Override]
    public function send(TestCase $test, CompiledRegistry $registry, QuerySurfaceCall $call): QueryAnswer
    {
        $answer = McpClient::call(ToolName::of($call->query, $call->version)->value, sprintf('{"query":%s}', $call->document), $call->credential);
        $answer->document();
        $text = $answer->list('result.content')[0]['text'] ?? null;

        return QueryAnswer::of(is_string($text) ? $text : '', $answer->isError(), $this->signal($answer->isError() ? McpResponse::ToolError : McpResponse::Result));
    }

    #[Override]
    public function queryPath(string $path): string
    {
        return $path === '' ? ToolCompiler::QUERY : ToolCompiler::QUERY.'.'.$path;
    }

    #[Override]
    public function transport(QueryScenario $scenario): string
    {
        return match ($scenario) {
            QueryScenario::DocumentRefused => $this->signal(ErrorCode::JsonInvalid->entry()->mcp),
            QueryScenario::Unauthorized => $this->signal(ErrorCode::Unauthorized->entry()->mcp),
            QueryScenario::Answered => $this->signal(McpResponse::Result),
        };
    }

    private function signal(McpResponse $response): string
    {
        return 'MCP '.$response->value;
    }
}
