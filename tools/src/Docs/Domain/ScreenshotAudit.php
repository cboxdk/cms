<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Holds the manifest of screenshots (Screenshots), the images in docs/screenshots and the pages
 * that embed them together:
 *
 * - every entry has a key of lowercase words joined by hyphens, used once, a caption and a command;
 * - every entry has its image, docs/screenshots/<key>.svg;
 * - every file in docs/screenshots but _index.md is the image of an entry;
 * - a page outside docs/screenshots embeds every entry, so no image is committed that no page
 *   describes;
 * - every image link to an entry's image has the entry's caption as its alt text, so the caption
 *   is written in one place.
 */
final readonly class ScreenshotAudit
{
    private const string KEY = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param  list<Screenshot>  $screenshots
     * @return list<Finding>
     */
    public static function findings(DocsTree $tree, array $screenshots): array
    {
        $findings = [];
        /** @var array<string, Screenshot> $byPath */
        $byPath = [];

        foreach ($screenshots as $shot) {
            if (preg_match(self::KEY, $shot->key) !== 1) {
                $findings[] = Finding::about($shot->key, 'the key of a screenshot is lowercase words and digits joined by hyphens');
            } elseif (isset($byPath[$shot->path()])) {
                $findings[] = Finding::about($shot->key, 'the key of a screenshot is used twice in Screenshots');
            }

            if (trim($shot->caption) === '' || $shot->command === []) {
                $findings[] = Finding::about($shot->key, 'a screenshot has a caption and a command');
            }

            $byPath[$shot->path()] = $shot;
        }

        $files = array_flip($tree->docsFiles);

        foreach ($byPath as $path => $shot) {
            if (! isset($files[$path])) {
                $findings[] = Finding::about($path, "missing; capture it with composer docs:screenshots -- --only={$shot->key}");
            }
        }

        foreach ($tree->docsFiles as $path) {
            if (dirname($path) === DocsLayout::SCREENSHOTS && basename($path) !== DocsLayout::INDEX && ! isset($byPath[$path])) {
                $findings[] = Finding::about($path, 'no entry of Cbox\Cms\Tooling\Docs\Domain\Screenshots names this file; add one, or remove the file');
            }
        }

        /** @var array<string, true> $embedded */
        $embedded = [];

        foreach ($tree->pages as $page) {
            foreach ($page->links as $link) {
                $resolved = $link->image && $link->isRelative() ? DocsLinks::resolve($page->path, $link->path()) : null;
                $shot = $resolved === null ? null : ($byPath[$resolved] ?? null);

                if (! $shot instanceof Screenshot) {
                    continue;
                }

                if (dirname($page->path) !== DocsLayout::SCREENSHOTS) {
                    $embedded[$shot->path()] = true;
                }

                if ($link->text !== $shot->caption) {
                    $findings[] = Finding::at($page->path, $link->line, "the alt text of {$shot->path()} is not the caption of its entry in Screenshots: {$shot->caption}");
                }
            }
        }

        foreach ($byPath as $path => $shot) {
            if (! isset($embedded[$path])) {
                $findings[] = Finding::about($path, 'no page outside docs/screenshots embeds it; embed it on the page that describes it, or remove its entry from Screenshots');
            }
        }

        return $findings;
    }
}
