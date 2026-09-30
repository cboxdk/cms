<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests;

use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Mcp\Domain\McpEndpoint;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\McpServiceProvider;
use Cbox\Cms\Mcp\McpSurface;
use Laravel\Mcp\Server\McpServiceProvider as LaravelMcpServiceProvider;

it('is loaded through package discovery, with laravel/mcp\'s provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKeys([McpServiceProvider::class, LaravelMcpServiceProvider::class])
        ->and(app()->getProviders(McpServiceProvider::class))->toHaveCount(1);
});

it('binds the MCP surface as the adapter\'s port and the tools once per process', function (): void {
    expect(app(McpEndpoint::class))->toBeInstanceOf(McpSurface::class)
        ->and(app(McpTools::class))->toBe(app(McpTools::class));
});

it('declares the module\'s classes as a scan root of cboxdk/cms', function (): void {
    expect(new McpServiceProvider(app())->scanRoots())->toEqual([new ScanRoot('cboxdk/cms', (string) realpath(__DIR__.'/../src'))]);
});
