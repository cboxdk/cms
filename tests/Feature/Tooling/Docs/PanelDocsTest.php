<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tooling\Docs\Domain\JsSuite;
use Cbox\Cms\Tooling\Docs\Domain\Link;
use Cbox\Cms\Tooling\Docs\Domain\Marker;
use Cbox\Cms\Tooling\Docs\Domain\MarkerKind;
use Cbox\Cms\Tooling\Docs\Domain\Page;
use Cbox\Cms\Tooling\Docs\Domain\PageParser;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/*
 * The documentation of the panel's extension model (PRD 13.4, section 2.7 of the panel extension
 * architecture), beyond what gate 10 holds every extension point to: each panel point that is not
 * #[Internal] has a page of its own in docs/addons/panel/points, which declares its props class
 * and its schema and no other extension point, embeds a running example, a TypeScript one run by
 * the JS unit suite where the point's kind runs code, and is listed on the section's page; each
 * kind of point has its page in docs/addons/panel/kinds, with a running example; and
 * docs/ui/components.md links the stories of every component of the kit once, under the group of
 * the Storybook it is in.
 */

/** The kinds whose contributions are data, which run no code of the addon. */
const PANEL_DOCS_DATA_KINDS = [PointKind::Action, PointKind::Nav, PointKind::Theme, PointKind::Data];

/**
 * Every panel point the packages declare that is not #[Internal]: its id, its props class, the
 * repo-relative path of its schema and its kind.
 *
 * @return array<string, array{class: class-string, schema: string, kind: PointKind, page: string}>
 */
function panelDocsPoints(): array
{
    $root = Phpstan::root();
    $points = [];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $source) {
        foreach (panelDocsPhpFiles($source) as $file) {
            $contents = (string) file_get_contents($file);

            if (! str_contains($contents, '#[PanelPoint(') || preg_match('/^namespace ([^;]+);/m', $contents, $namespace) !== 1 || preg_match('/^final readonly class (\w+)/m', $contents, $class) !== 1) {
                continue;
            }

            $name = $namespace[1].'\\'.$class[1];

            if (! class_exists($name)) {
                throw new RuntimeException("{$file} declares {$name}, which does not autoload.");
            }

            $reflection = new ReflectionClass($name);
            $attributes = $reflection->getAttributes(PanelPoint::class);

            if ($attributes === [] || $reflection->getAttributes(Internal::class) !== []) {
                continue;
            }

            $point = $attributes[0]->newInstance();
            $schemas = glob($root.'/packages/*/resources/schemas/points/'.$point->name.'.v'.$point->version.'.json') ?: [];

            expect($schemas)->toHaveCount(1, "{$point->id()->toString()} has no schema of its own.");

            $slug = str_replace('.', '-', $point->name).($point->version === 1 ? '' : '-v'.$point->version);
            $points[$point->id()->toString()] = [
                'class' => $reflection->getName(),
                'schema' => substr($schemas[0], strlen($root) + 1),
                'kind' => $point->kind,
                'page' => "docs/addons/panel/points/{$slug}.md",
            ];
        }
    }

    ksort($points, SORT_STRING);

    return $points;
}

/**
 * @return list<string>
 */
function panelDocsPhpFiles(string $directory): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

function panelDocsPage(string $path): Page
{
    $contents = @file_get_contents(Phpstan::root().'/'.$path);

    return $contents === false ? throw new RuntimeException("{$path} does not exist.") : PageParser::parse($path, $contents);
}

/**
 * The repo-relative paths the page's relative links point at, without fragments.
 *
 * @return list<string>
 */
function panelDocsLinkedPaths(Page $page): array
{
    $directory = dirname($page->path);
    $paths = [];

    foreach (array_filter($page->links, static fn (Link $link): bool => $link->isRelative() && $link->path() !== '') as $link) {
        $parts = [];

        foreach (explode('/', $directory.'/'.$link->path()) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        $paths[] = implode('/', $parts);
    }

    return $paths;
}

/**
 * @return list<string>
 */
function panelDocsTargets(Page $page, MarkerKind $kind): array
{
    return array_map(static fn (Marker $marker): string => $marker->target, $page->markers($kind));
}

/**
 * The repo-relative Markdown files of a directory below docs/, without its _index.md.
 *
 * @return list<string>
 */
function panelDocsPagesIn(string $directory): array
{
    $root = Phpstan::root();
    $pages = array_map(static fn (string $path): string => substr($path, strlen($root) + 1), glob($root.'/'.$directory.'/*.md') ?: []);

    return array_values(array_filter($pages, static fn (string $path): bool => basename($path) !== '_index.md'));
}

it('gives every panel point that is not internal a page of its own with its props class, its schema and a running example', function (): void {
    $points = panelDocsPoints();
    $index = panelDocsLinkedPaths(panelDocsPage('docs/addons/panel/points/_index.md'));

    expect(count($points))->toBeGreaterThanOrEqual(15);

    foreach ($points as $id => $point) {
        $page = panelDocsPage($point['page']);
        $examples = panelDocsTargets($page, MarkerKind::Example);

        expect(panelDocsTargets($page, MarkerKind::ExtensionPoint))->toBe([$point['class'], $point['schema']], "{$point['page']} declares {$id}'s props class and schema, in that order, and nothing else.")
            ->and($examples)->not->toBe([], "{$point['page']} embeds no running example.")
            ->and($index)->toContain($point['page']);

        if (! in_array($point['kind'], PANEL_DOCS_DATA_KINDS, true)) {
            expect(array_filter($examples, JsSuite::isTestFile(...)))->not->toBe([], "{$point['page']} documents {$id}, whose contributions run code, without a TypeScript example.");
        }
    }

    expect(panelDocsPagesIn('docs/addons/panel/points'))->toEqualCanonicalizing(array_column($points, 'page'));
});

it('gives every kind of panel point a page with a running example, listed on the section s page', function (): void {
    $index = panelDocsLinkedPaths(panelDocsPage('docs/addons/panel/kinds/_index.md'));
    $pages = [];

    foreach (PointKind::cases() as $kind) {
        $path = 'docs/addons/panel/kinds/'.str_replace('_', '-', $kind->value).'.md';
        $pages[] = $path;

        expect(panelDocsTargets(panelDocsPage($path), MarkerKind::Example))->not->toBe([], "{$path} embeds no running example.")
            ->and($index)->toContain($path);
    }

    expect(panelDocsPagesIn('docs/addons/panel/kinds'))->toEqualCanonicalizing($pages);
});

it('links the stories of every component of the kit once, under the group of the Storybook it is in', function (): void {
    $root = Phpstan::root();
    $page = panelDocsPage('docs/ui/components.md');
    $lines = explode("\n", (string) file_get_contents($root.'/docs/ui/components.md'));
    $linked = [];

    foreach (array_filter($page->links, static fn (Link $link): bool => str_ends_with($link->path(), '.stories.tsx')) as $link) {
        $heading = null;

        for ($line = $link->line - 1; $line >= 0 && $heading === null; $line--) {
            $heading = preg_match('/^## (.+)$/', $lines[$line] ?? '', $match) === 1 ? $match[1] : null;
        }

        $linked[] = ($heading ?? '').'/'.basename($link->path(), '.stories.tsx');
    }

    $stories = [];

    foreach (glob($root.'/js/ui-kit/stories/*.stories.tsx') ?: [] as $file) {
        if (preg_match("/title: '(?:Components\\/)?([^'\\/]+)\\/([^'\\/]+)'/", (string) file_get_contents($file), $title) !== 1) {
            throw new RuntimeException("{$file} has no title of a group and a component.");
        }

        $stories[] = $title[1].'/'.$title[2];
    }

    expect($stories)->not->toBe([])
        ->and($linked)->toEqualCanonicalizing($stories);
});
