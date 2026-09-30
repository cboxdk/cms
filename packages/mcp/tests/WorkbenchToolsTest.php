<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Core\Registry\Boundary\ProviderAddonManifests;
use Cbox\Cms\Core\Registry\Boundary\ProviderScanRoots;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Mcp\Adapter\McpRoutes;
use Cbox\Cms\Mcp\Domain\Dto\McpTool;
use Cbox\Cms\Mcp\Domain\Dto\UndescribedTool;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Tests\Support\McpAnswer;
use Cbox\Cms\Mcp\Tests\Support\McpClient;
use Cbox\Cms\Mcp\Tests\Support\McpWorld;
use Illuminate\Support\Facades\Route;

/*
 * The MCP tools of the workbench (GUARDRAILS 2.1: MCP tools are generated from the registry). The
 * registry is compiled here as cms:build compiles it, from the scan roots and addon manifests of
 * the installation's service providers, and read through the container as the MCP surface reads it
 * from the cache cms:build wrote. The kernel's commands that list Surface::Mcp are exposed, but no
 * codec reads them yet, so the surface cannot describe their arguments and names each with the
 * reason instead of offering it. With the MCP fixtures' scan roots added, the test-only write and
 * query are offered, and the workbench's endpoint lists them and runs the write.
 */

/**
 * The registry of the installation's scan roots and the roots given, compiled as cms:build does.
 */
function workbenchRegistry(McpWorld ...$with): CompiledRegistry
{
    $roots = ProviderScanRoots::of(app())->roots;

    if ($with !== []) {
        $roots[] = McpWorld::fixtures();
        $roots[] = McpWorld::probe();
    }

    return app(RegistryCompiler::class)->compile(app(DeclarationScanner::class)->scan(new ScanRoots(...$roots)), ProviderAddonManifests::of(app()));
}

/**
 * The commands and queries of the actions the registry exposes on MCP, each as "<name> <version>".
 *
 * @return list<string>
 */
function workbenchMcpActions(CompiledRegistry $registry): array
{
    $actions = array_values(array_map(
        static fn (ActionEntry $action): string => $action->command->value.' '.$action->commandVersion,
        array_filter($registry->actions, static fn (ActionEntry $action): bool => $action->exposes(Surface::Mcp)),
    ));
    sort($actions);

    return $actions;
}

/**
 * @return list<string> each as "<name> <version>"
 */
function undescribedActions(McpTools $tools): array
{
    $actions = array_map(static fn (UndescribedTool $tool): string => $tool->action->command->value.' '.$tool->action->commandVersion, $tools->undescribed);
    sort($actions);

    return $actions;
}

it('names each kernel command the workbench exposes on MCP without a codec, with the reason, and offers no tool for it', function (): void {
    $registry = workbenchRegistry();
    $exposed = workbenchMcpActions($registry);
    $tools = app(McpTools::class);

    expect($exposed)->toBe(['entry.create 1', 'entry.publish 1', 'entry.revise 1', 'entry.unpublish 1', 'placement.create 1', 'placement.set_window 1', 'variant.release 1'])
        ->and(workbenchMcpActions(app(CompiledRegistry::class)))->toBe($exposed)
        ->and($tools->tools)->toBe([])
        ->and(undescribedActions($tools))->toBe($exposed)
        ->and($tools->undescribed[0]->reason)->toContain('No codec reads version 1 of the command')
        ->and($tools->undescribed[0]->reason)->toContain('cbox-cms.command-codecs');
});

it('lists the compiled tools of the workbench with the test-only actions and runs one through the workbench\'s endpoint', function (): void {
    $world = new McpWorld;
    $registry = workbenchRegistry($world);
    $tools = McpWorld::tools($registry);
    $world->bind(app(), $tools);

    expect(Route::has(McpRoutes::NAME))->toBeTrue()
        ->and(array_map(static fn (McpTool $tool): string => $tool->name->value, $tools->tools))->toBe([McpWorld::READ_TOOL, McpWorld::WRITE_TOOL])
        ->and(undescribedActions($tools))->toBe(['entry.create 1', 'entry.publish 1', 'entry.revise 1', 'entry.unpublish 1', 'placement.create 1', 'placement.set_window 1', 'variant.release 1']);

    $listed = McpClient::tools();
    $called = McpClient::call(McpWorld::WRITE_TOOL, McpClient::writeArguments($world, ['idempotency_key' => 'mcp-workbench']), $world->exposed->credential(IssuerKind::Agent));

    expect(McpAnswer::names($listed))->toBe([McpWorld::READ_TOOL, McpWorld::WRITE_TOOL])
        ->and($called->isError())->toBeFalse()
        ->and($called->document()['outcome'] ?? null)->toBe('committed')
        ->and($world->exposed->world->committer->pending)->toHaveCount(1);
});
