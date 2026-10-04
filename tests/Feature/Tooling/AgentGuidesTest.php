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

/*
 * The guides are slim on purpose (Sylvester, 4 October 2026): the architecture notes live on
 * the pages below docs/developers/architecture, and CLAUDE.md names each one. AGENTS.md carries
 * the same text from "Sources of truth" on, so a tool that reads only one of them reads the rules.
 */

function guideFrom(string $guide, string $heading): string
{
    $text = (string) file_get_contents(Codebase::root().'/'.$guide);
    $at = strpos($text, "\n{$heading}\n");

    return $at === false ? '' : substr($text, $at);
}

it('has the same text from "Sources of truth" on in CLAUDE.md and AGENTS.md', function (): void {
    $claude = guideFrom('CLAUDE.md', '## Sources of truth');

    expect($claude)->toContain('## Hard rules', '## Hvor ting bor', '### Architecture notes', '## When the PRD is unclear', '## Autopilot')
        ->and(guideFrom('AGENTS.md', '## Sources of truth'))->toBe($claude);
});

it('names every architecture page under "Architecture notes" and names no page that does not exist', function (): void {
    $section = guideFrom('CLAUDE.md', '### Architecture notes');
    $section = substr($section, 0, strpos($section, "\n## ") ?: strlen($section));

    preg_match_all('/^- `([a-z0-9-]+\.md)`: /m', $section, $matches);

    $named = $matches[1];
    $pages = array_values(array_filter(
        array_map(basename(...), glob(Codebase::root().'/docs/developers/architecture/*.md') ?: []),
        static fn (string $page): bool => $page !== '_index.md',
    ));

    sort($named);
    sort($pages);

    expect($named)->not->toBe([])
        ->and($named)->toBe($pages)
        ->and(is_file(Codebase::root().'/docs/developers/architecture/_index.md'))->toBeTrue();
});
