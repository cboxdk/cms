<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Mcp\Adapter\McpServer;
use Cbox\Cms\Mcp\Domain\McpEndpoint;
use Cbox\Cms\Mcp\Domain\ToolCallRefused;
use Cbox\Cms\Mcp\McpSurface;
use Cbox\Cms\Tests\Support\Arch\Category;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Illuminate\Contracts\Container\Container;
use Override;
use ReflectionClass;
use ReflectionMethod;

/*
 * The MCP surface (GUARDRAILS 1, 2.1): the mcp module, Cbox\Cms\Mcp, is a surface in the layers of
 * GUARDRAILS 2.5 and a module of the package (ModulesTest). laravel/mcp sits behind the module's
 * internal adapter: only Cbox\Cms\Mcp\Adapter uses it, so the package can follow its releases in
 * one place. The surface holds no logic (GUARDRAILS 2.1: MCP tools build the envelope and the DTO,
 * call the action and translate the result, and the architecture tests enforce it): McpSurface
 * uses only actions, DTOs, Boundary classes, the port it implements, the refusal the Boundary
 * throws, the contracts' stability attributes and the container it resolves an action from when a
 * call needs one, and declares no method but the port's.
 */

const MCP_ADAPTER = 'Cbox\Cms\Mcp\Adapter';

arch('mcp surface: the mcp module is a surface of the layers', function (): void {
    expect(Layer::surfaces())->toContain(Layer::Mcp)
        ->and(Layer::of(McpSurface::class))->toBe(Layer::Mcp)
        ->and(Layer::of(McpServer::class))->toBe(Layer::Adapter)
        ->and(Codebase::classesIn(Layer::Mcp))->toContain(McpSurface::class);
});

arch('mcp surface: only the module\'s adapter uses laravel/mcp', function (): void {
    $outside = array_values(array_map(
        static fn (DeclaredType $type): string => $type->fqcn(),
        array_filter(Codebase::types(), static fn (DeclaredType $type): bool => ! str_starts_with($type->fqcn(), MCP_ADAPTER.'\\')),
    ));

    expect(Codebase::classesIn(Layer::Adapter))->toContain(McpServer::class);

    Rules::forbid($outside, ['Laravel\Mcp']);
});

arch('mcp surface: McpSurface uses only actions, DTOs, Boundary and the port it implements', function (): void {
    expect(McpSurface::class)->toOnlyUse([
        ...Codebase::classesIn(Layer::Actions),
        ...Codebase::classesIn(Layer::Boundary),
        ...Codebase::classesInCategory(Category::Dto),
        McpEndpoint::class,
        ToolCallRefused::class,
        'Cbox\Cms\Contracts\Attributes',
        Container::class,
        Override::class,
    ]);
});

arch('mcp surface: McpSurface declares no method but its constructor and the port\'s', function (): void {
    $declared = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            new ReflectionClass(McpSurface::class)->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === McpSurface::class,
        ),
    ));
    sort($declared);

    expect(new ReflectionClass(McpSurface::class)->isFinal())->toBeTrue()
        ->and($declared)->toBe(['__construct', 'call', 'tools']);
});
