<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * No relative link on a page below docs/, or in README.md, dangles: its path, from the page's
 * folder, stays inside the repository and names a file or folder that exists, and a fragment on a
 * link to a Markdown file, or on a link to a heading of the same page, names a heading there. A link
 * with a scheme, such as https: or mailto:, is not checked.
 */
final readonly class DocsLinks
{
    /**
     * @return list<Finding>
     */
    public static function findings(DocsTree $tree): array
    {
        $findings = [];
        $pages = $tree->readme instanceof Page ? [$tree->readme, ...$tree->pages] : $tree->pages;

        foreach ($pages as $page) {
            foreach ($page->links as $link) {
                if ($link->isRelative()) {
                    array_push($findings, ...self::linkFindings($page, $link, $tree->files));
                }
            }
        }

        return $findings;
    }

    /**
     * The repo-relative path a relative link path on the page names, without a trailing slash, or
     * null when it leaves the repository. The empty string is the root.
     */
    public static function resolve(string $page, string $path): ?string
    {
        $directory = dirname($page);
        $segments = $directory === '.' ? [] : explode('/', $directory);

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * @return list<Finding>
     */
    private static function linkFindings(Page $page, Link $link, RepositoryFiles $files): array
    {
        $path = $link->path();
        $fragment = $link->fragment();

        if ($path === '') {
            return $fragment === null || $fragment === '' || in_array($fragment, $page->anchors, true)
                ? []
                : [Finding::at($page->path, $link->line, "the link {$link->target} names the heading #{$fragment}, which the page does not have")];
        }

        $resolved = self::resolve($page->path, $path);

        if ($resolved === null) {
            return [Finding::at($page->path, $link->line, "the link {$link->target} leaves the repository")];
        }

        if (! $files->exists($resolved)) {
            return [Finding::at($page->path, $link->line, "the link {$link->target} points to {$resolved}, which does not exist")];
        }

        if ($fragment === null || $fragment === '' || ! str_ends_with($resolved, '.md')) {
            return [];
        }

        $anchors = PageParser::parse($resolved, $files->contents($resolved) ?? '')->anchors;

        return in_array($fragment, $anchors, true)
            ? []
            : [Finding::at($page->path, $link->line, "the link {$link->target} names the heading #{$fragment}, which {$resolved} does not have")];
    }
}
