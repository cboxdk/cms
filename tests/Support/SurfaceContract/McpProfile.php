<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Contracts\Identity\IssuerKind;
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
use stdClass;

/**
 * MCP with tool errors (GUARDRAILS 2.1, PRD 2.31): the tools compiled from the registry with the
 * installation's codecs, and a tools/call of the command's tool on the workbench's endpoint, with
 * the command's document and the envelope as its arguments and an agent's credential as the
 * Bearer token. A rejection is a tool error whose document is the problem, with paths below
 * `command`; a receipt is a tool result, the catalog's McpResponse for each.
 */
final readonly class McpProfile implements SurfaceProfile
{
    #[Override]
    public function surface(): Surface
    {
        return Surface::Mcp;
    }

    #[Override]
    public function prepare(TestCase $test, CompiledRegistry $registry, ContractKernel $kernel): void
    {
        app()->instance(McpTools::class, new ToolCompiler()->compile($registry, app(CommandCodecs::class), app(QueryCodecs::class), KernelSchemas::envelope()));
    }

    #[Override]
    public function credential(ContractKernel $kernel): TransportCredential
    {
        return $kernel->exposed->credential(IssuerKind::Agent);
    }

    #[Override]
    public function send(TestCase $test, CompiledRegistry $registry, SurfaceCall $call): SurfaceAnswer
    {
        $envelope = new stdClass;
        $envelope->idempotency_key = $call->key;
        $envelope->dry_run = $call->dryRun;
        $envelope->wait_level = $call->waitLevel->value;

        $answer = McpClient::call(
            ToolName::of($call->command, $call->version)->value,
            sprintf('{"command":%s,"envelope":%s}', $call->document, json_encode($envelope, JSON_THROW_ON_ERROR)),
            $call->credential,
        );

        return SurfaceAnswer::of($answer->document(), $this->signal($answer->isError() ? McpResponse::ToolError : McpResponse::Result));
    }

    #[Override]
    public function commandPath(string $path): string
    {
        return 'command.'.$path;
    }

    #[Override]
    public function transport(Scenario $scenario, string $path): string
    {
        return match ($scenario) {
            Scenario::DocumentFieldError => $this->signal(ErrorCode::JsonInvalid->entry()->mcp),
            Scenario::FieldError => $this->signal(ErrorCode::ValidationFailed->entry()->mcp),
            Scenario::VersionConflict => $this->signal(ErrorCode::VersionConflict->entry()->mcp),
            Scenario::DryRun => $this->signal(ErrorCode::DryRun->entry()->mcp),
            Scenario::WaitTimeout => $this->signal(McpResponse::Result),
        };
    }

    private function signal(McpResponse $response): string
    {
        return 'MCP '.$response->value;
    }
}
