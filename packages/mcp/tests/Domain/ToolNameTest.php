<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Domain;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Mcp\Domain\InvalidToolName;
use Cbox\Cms\Mcp\Domain\ToolName;

/*
 * The name of an MCP tool: the command's name with hyphens for its dots, -v and the version, in the
 * characters and length every MCP client takes.
 */

it('names a tool after its command or query and version', function (string $command, int $version, string $name): void {
    expect(ToolName::of(new CommandName($command), $version)->value)->toBe($name);
})->with([
    ['entry.create', 1, 'entry-create-v1'],
    ['placement.set_window', 12, 'placement-set_window-v12'],
    ['probe.agent_cards', 1, 'probe-agent_cards-v1'],
]);

it('gives two commands that differ in their dots and underscores two names', function (): void {
    expect(ToolName::of(new CommandName('a.b_c'), 1)->value)->not->toBe(ToolName::of(new CommandName('a_b.c'), 1)->value);
});

it('refuses text that is not a tool name', function (string $value): void {
    expect(static fn (): ToolName => new ToolName($value))->toThrow(InvalidToolName::class, 'is not the name of an MCP tool');
})->with(['entry.create.v1', 'entry-create', 'entry-create-v0', 'Entry-create-v1', 'create-v1', '']);

it('refuses a name longer than MCP clients take', function (): void {
    $command = new CommandName('probe.'.str_repeat('a', 56));

    expect(ToolName::of(new CommandName('probe.'.str_repeat('a', 55)), 1)->value)->toHaveLength(ToolName::MAX_LENGTH)
        ->and(static fn (): ToolName => ToolName::of($command, 1))->toThrow(InvalidToolName::class, 'longer than 64 characters');
});
