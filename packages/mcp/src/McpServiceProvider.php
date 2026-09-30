<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Mcp\Boundary\KernelSchemas;
use Cbox\Cms\Mcp\Boundary\ToolCompiler;
use Cbox\Cms\Mcp\Domain\McpEndpoint;
use Cbox\Cms\Mcp\Domain\McpTools;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the mcp module in a Laravel application. Loaded through package discovery.
 *
 * Declares the module's classes as a scan root for cms:build (PRD 13.2), binds the MCP tools,
 * compiled once per process from the registry cms:build compiles and the codecs the commands and
 * queries register, and binds the MCP surface as the port the adapter's server asks. An
 * application serves the tools by registering McpRoutes.
 */
#[Internal]
final class McpServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            McpTools::class,
            static fn (Application $app): McpTools => new ToolCompiler()->compile(
                $app->make(CompiledRegistry::class),
                $app->make(CommandCodecs::class),
                $app->make(QueryCodecs::class),
                KernelSchemas::envelope(),
            ),
        );
        $this->app->bind(McpEndpoint::class, McpSurface::class);
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
