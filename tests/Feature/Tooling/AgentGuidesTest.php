<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Codebase;

/*
 * CLAUDE.md and AGENTS.md describe where code lives. Agents read one or the other, so the
 * section must be the same in both.
 */

function whereThingsLive(string $guide): string
{
    $text = (string) file_get_contents(Codebase::root().'/'.$guide);

    if (preg_match('/^## Hvor ting bor\n.*?(?=^## |\z)/ms', $text, $match) !== 1) {
        return '';
    }

    return trim($match[0]);
}

it('has the same "Hvor ting bor" section in CLAUDE.md and AGENTS.md', function (): void {
    $claude = whereThingsLive('CLAUDE.md');

    expect($claude)->toContain('| `Domain` |', '| `Adapter` |', '| `Infrastructure` |', Codebase::GATEWAY)
        ->and(whereThingsLive('AGENTS.md'))->toBe($claude);
});
