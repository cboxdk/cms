<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Domain;

use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Mcp\Domain\Dto\McpTool;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Domain\ToolName;
use Cbox\Cms\Mcp\Tests\Support\McpWorld;
use InvalidArgumentException;

/*
 * The tools of the MCP surface: sorted by name, found by name, each a write or a read.
 */

function mcpTool(string $command): McpTool
{
    return new McpTool(ToolName::of(new CommandName($command), 1), $command, 'A tool.', new JsonSchema('{"type":"object"}'), ExposedWorld::codec(), null);
}

it('sorts the tools by name and finds one by its name', function (): void {
    $tools = new McpTools([mcpTool('probe.write'), mcpTool('probe.alpha')]);

    expect(array_map(static fn (McpTool $tool): string => $tool->name->value, $tools->tools))->toBe(['probe-alpha-v1', 'probe-write-v1'])
        ->and($tools->find('probe-write-v1'))->toBe($tools->tools[1])
        ->and($tools->find('probe-other-v1'))->toBeNull()
        ->and($tools->undescribed)->toBe([]);
});

it('refuses two tools with one name', function (): void {
    expect(static fn (): McpTools => new McpTools([mcpTool('probe.write'), mcpTool('probe.write')]))->toThrow(InvalidArgumentException::class, 'Two MCP tools are named probe-write-v1.');
});

it('runs a command or a query, exactly one of them, and only reads when it runs a query', function (): void {
    $name = ToolName::of(new CommandName('probe.write'), 1);
    $schema = new JsonSchema('{"type":"object"}');

    expect(mcpTool('probe.write')->readsOnly())->toBeFalse()
        ->and(new McpTool($name, 't', 'd', $schema, null, McpWorld::queryCodec())->readsOnly())->toBeTrue()
        ->and(static fn (): McpTool => new McpTool($name, 't', 'd', $schema, null, null))->toThrow(InvalidArgumentException::class, 'must run a command or a query, and only one of them')
        ->and(static fn (): McpTool => new McpTool($name, 't', 'd', $schema, ExposedWorld::codec(), McpWorld::queryCodec()))->toThrow(InvalidArgumentException::class);
});
