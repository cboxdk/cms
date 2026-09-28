<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tooling\Docs\Boundary\LocalDocsTree;
use Cbox\Cms\Tooling\Docs\Domain\DocsAudit;
use Cbox\Cms\Tooling\Docs\Domain\DocsTree;
use Cbox\Cms\Tooling\Docs\Domain\Embed;
use Cbox\Cms\Tooling\Docs\Domain\Exclusions;
use Cbox\Cms\Tooling\Docs\Domain\Finding;
use Cbox\Cms\Tooling\Docs\Domain\MarkerKind;
use Cbox\Cms\Tooling\Docs\Domain\Page;
use Cbox\Cms\Tooling\Docs\Domain\PageParser;
use Cbox\Cms\Tooling\Docs\Domain\Screenshots;
use RuntimeException;

/*
 * Gate 10 of GUARDRAILS 10 on this repository, in gate 5. The PR profile runs gate 10 as
 * `composer docs:check`, and the local profile leaves the gate out (GUARDRAILS 10); this test runs
 * the same audit, with the same exclusions and screenshots, in the Unit suite, so `composer check`
 * fails on every finding the merge queue fails on (GUARDRAILS 7.3): a renamed example, a page whose
 * code drifted from the tested file, a new public extension point without a page, a page out of
 * the docs/ layout or a dangling link. The fixture case changes one byte of an embedded example on
 * each page that embeds one and shows that the audit then has that finding.
 */

/**
 * The findings of gate 10 on the tree, as `composer docs:check` prints them.
 *
 * @return list<string>
 */
function repositoryDocsFindings(DocsTree $tree): array
{
    return array_map(static fn (Finding $finding): string => (string) $finding, DocsAudit::findings($tree, Exclusions::all(), Screenshots::all()));
}

/**
 * The tree with the page at the path parsed again from its contents with one byte of the block of
 * its first example changed: a letter in the middle of the block becomes another letter. Returns
 * the tree and the finding the change must give.
 *
 * @return array{DocsTree, string}
 */
function repositoryDocsWithOneByteChanged(DocsTree $tree, string $path): array
{
    $page = array_first(array_filter($tree->pages, static fn (Page $page): bool => $page->path === $path))
        ?? throw new RuntimeException("{$path} is no page of the tree.");
    $embed = $page->embeds(MarkerKind::Example)[0] ?? throw new RuntimeException("{$path} has no example.");
    $contents = $tree->files->contents($path) ?? throw new RuntimeException("{$path} cannot be read.");
    $block = repositoryDocsBlockOffset($contents, $embed);
    $offset = $block + repositoryDocsLetterOffset($embed->body);
    $changed = substr_replace($contents, $contents[$offset] === 'x' ? 'y' : 'x', $offset, 1);

    $pages = array_map(static fn (Page $each): Page => $each->path === $path ? PageParser::parse($path, $changed) : $each, $tree->pages);

    return [
        new DocsTree($tree->sources, $tree->schemas, $pages, $tree->examples, $tree->suites, $tree->files, $tree->readme, $tree->docsFiles, $tree->docsDirectories, $tree->strayPages),
        "{$path}:{$embed->marker->line}: the fenced block differs from {$embed->marker->target}; embed the file byte for byte",
    ];
}

/**
 * The offset in the page's contents where the block of the embed starts: after the marker's line
 * and the opening fence's line.
 */
function repositoryDocsBlockOffset(string $contents, Embed $embed): int
{
    $lines = explode("\n", $contents);
    $offset = strlen(implode("\n", array_slice($lines, 0, $embed->marker->line + 1))) + 1;

    if ($lines[$embed->marker->line - 1] !== $embed->marker->text() || substr($contents, $offset, strlen($embed->body)) !== $embed->body) {
        throw new RuntimeException("The block of {$embed->marker->target} is not below its marker.");
    }

    return $offset;
}

/**
 * The offset of the first ASCII letter from the middle of the block on.
 */
function repositoryDocsLetterOffset(string $body): int
{
    $length = strlen($body);

    for ($offset = intdiv($length, 2); $offset < $length; $offset++) {
        if (ctype_alpha($body[$offset])) {
            return $offset;
        }
    }

    throw new RuntimeException('The block has no letter in its second half.');
}

it('finds nothing in this repository: every extension point has its page, and every page its running examples, byte for byte', function (): void {
    expect(repositoryDocsFindings(LocalDocsTree::read(Phpstan::root())))->toBe([]);
});

it('finds the byte changed in the first example block of the page', function (string $path): void {
    [$tree, $finding] = repositoryDocsWithOneByteChanged(LocalDocsTree::read(Phpstan::root()), $path);

    expect(repositoryDocsFindings($tree))->toBe([$finding]);
})->with(static fn (): array => array_map(
    static fn (Page $page): string => $page->path,
    array_values(array_filter(LocalDocsTree::read(Phpstan::root())->pages, static fn (Page $page): bool => $page->embeds(MarkerKind::Example) !== [])),
));

it('has a running example on every page that documents an extension point, so the case above covers each of them', function (): void {
    $pages = array_filter(LocalDocsTree::read(Phpstan::root())->pages, static fn (Page $page): bool => $page->markers(MarkerKind::ExtensionPoint) !== []);

    expect($pages)->not->toBe([])
        ->and(array_values(array_filter($pages, static fn (Page $page): bool => $page->embeds(MarkerKind::Example) === [])))->toBe([]);
});
