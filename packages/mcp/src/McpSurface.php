<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Mcp\Boundary\ToolInput;
use Cbox\Cms\Mcp\Boundary\ToolOutput;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use Cbox\Cms\Mcp\Domain\Dto\ToolCall;
use Cbox\Cms\Mcp\Domain\Dto\ToolListing;
use Cbox\Cms\Mcp\Domain\McpEndpoint;
use Cbox\Cms\Mcp\Domain\ToolCallRefused;
use Illuminate\Contracts\Container\Container;
use Override;

/**
 * The MCP surface (GUARDRAILS 2.1, PRD 2.31, 22): the tools of every action the registry exposes on
 * Surface::Mcp, for agents. ToolInput reads a call, the shared core action RunExposedCommand runs a
 * write and the query pipeline a read, each as the agent of the credential the transport carried,
 * and ToolOutput translates the typed result into a tool result or a tool error with the catalog's
 * codes. The surface holds no logic of its own; the adapter reaches it through McpEndpoint. The
 * actions are resolved when a call needs one, so listing the tools needs neither pipeline.
 */
#[Internal]
final readonly class McpSurface implements McpEndpoint
{
    public function __construct(
        private ToolInput $input,
        private ToolOutput $output,
        private Container $container,
    ) {}

    #[Override]
    public function tools(): ToolListing
    {
        try {
            return $this->output->listing($this->input->catalog());
        } catch (ToolCallRefused $refused) {
            return $this->output->unlisted($refused);
        }
    }

    #[Override]
    public function call(ToolCall $call): ToolAnswer
    {
        try {
            $request = $this->input->read($call);
        } catch (ToolCallRefused $refused) {
            return $this->output->refused($refused);
        }

        return $request instanceof ExposedCall
            ? $this->output->written($this->container->make(RunExposedCommand::class)->run($request))
            : $this->output->read($this->container->make(QueryPipeline::class)->run($request->call), $request->codec);
    }
}
