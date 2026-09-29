<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The layout of docs/, the cboxdk docs standard:
 *
 * - the root of docs/ holds only index.md, quickstart.md and requirements.md, and all three;
 * - every other page is in a topic folder, and every folder below docs/, a nested one too, has
 *   an _index.md, the section page;
 * - every page starts with frontmatter that has a title, a whole-number weight and a description,
 *   and the weight of an _index.md is lower than the weight of every other page in its folder,
 *   so the section page comes first;
 * - below docs/ there are only Markdown pages and the screenshots of Screenshots, in
 *   docs/screenshots;
 * - no Markdown file is below packages/: the documentation is in docs/;
 * - the root of the repository has README.md, LICENSE, SECURITY.md and CONTRIBUTING.md, the files
 *   GitHub shows a visitor, and DocsLinks checks the links of the three Markdown files as it checks
 *   a page's.
 */
final readonly class DocsLayout
{
    public const string ROOT = 'docs';

    public const string README = 'README.md';

    /**
     * The Markdown files at the root of the repository whose links are checked, README.md first.
     *
     * @var list<string>
     */
    public const array ROOT_MARKDOWN = [self::README, 'CONTRIBUTING.md', 'SECURITY.md'];

    /**
     * The files the root of the repository must have.
     *
     * @var list<string>
     */
    public const array REPOSITORY_FILES = [self::README, 'CONTRIBUTING.md', 'LICENSE', 'SECURITY.md'];

    public const string SCREENSHOTS = 'docs/screenshots';

    public const string INDEX = '_index.md';

    /**
     * The pages at the root of docs/, and the only files there.
     *
     * @var list<string>
     */
    public const array ROOT_PAGES = ['index.md', 'quickstart.md', 'requirements.md'];

    /**
     * @return list<Finding>
     */
    public static function findings(DocsTree $tree): array
    {
        $findings = [];
        $files = array_flip($tree->docsFiles);

        foreach (self::REPOSITORY_FILES as $path) {
            if ($tree->files->contents($path) === null) {
                $findings[] = Finding::about($path, 'missing; the root of the repository has README.md, LICENSE, SECURITY.md and CONTRIBUTING.md');
            }
        }

        foreach (self::ROOT_PAGES as $name) {
            if (! isset($files[self::ROOT.'/'.$name])) {
                $findings[] = Finding::about(self::ROOT.'/'.$name, 'missing; the root of docs/ holds index.md, quickstart.md and requirements.md');
            }
        }

        foreach ($tree->docsFiles as $path) {
            $directory = dirname($path);

            if ($directory === self::ROOT && ! in_array(basename($path), self::ROOT_PAGES, true)) {
                $findings[] = Finding::about($path, 'the root of docs/ holds only index.md, quickstart.md and requirements.md; move the file into a topic folder');
            } elseif (! str_ends_with($path, '.md') && $directory !== self::SCREENSHOTS) {
                $findings[] = Finding::about($path, 'below docs/ there are only Markdown pages, and the screenshots of Cbox\Cms\Tooling\Docs\Domain\Screenshots in docs/screenshots');
            }
        }

        foreach ($tree->docsDirectories as $directory) {
            if (! isset($files[$directory.'/'.self::INDEX])) {
                $findings[] = Finding::about($directory, 'the folder has no _index.md; every folder below docs/ has one, with title, weight and description frontmatter');
            }
        }

        foreach ($tree->pages as $page) {
            array_push($findings, ...self::frontmatterFindings($page));
        }

        array_push($findings, ...self::weightFindings($tree->pages));

        foreach ($tree->strayPages as $path) {
            $findings[] = Finding::about($path, 'the documentation is in docs/ at the root of the repository; move the page there');
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private static function frontmatterFindings(Page $page): array
    {
        $frontmatter = $page->frontmatter;

        if (! $frontmatter instanceof Frontmatter) {
            return [Finding::at($page->path, 1, 'the page has no frontmatter; start it with a line ---, then title, weight and description, then a line ---')];
        }

        $findings = [];

        foreach ($frontmatter->invalidLines as $line) {
            $findings[] = Finding::at($page->path, $line, 'the frontmatter line is not key: value');
        }

        foreach (Frontmatter::REQUIRED as $key) {
            $value = $frontmatter->value($key);

            if ($value === null || $value === '') {
                $findings[] = Finding::at($page->path, 1, "the frontmatter has no {$key}");
            }
        }

        if ($frontmatter->value('weight') !== null && $frontmatter->value('weight') !== '' && $frontmatter->weight() === null) {
            $findings[] = Finding::at($page->path, 1, 'the frontmatter weight is not a whole number');
        }

        return $findings;
    }

    /**
     * @param  list<Page>  $pages
     * @return list<Finding>
     */
    private static function weightFindings(array $pages): array
    {
        /** @var array<string, int> $indexWeights */
        $indexWeights = [];

        foreach ($pages as $page) {
            $weight = $page->frontmatter?->weight();

            if (basename($page->path) === self::INDEX && $weight !== null) {
                $indexWeights[dirname($page->path)] = $weight;
            }
        }

        $findings = [];

        foreach ($pages as $page) {
            $directory = dirname($page->path);
            $weight = $page->frontmatter?->weight();

            if (basename($page->path) === self::INDEX || $weight === null || ! isset($indexWeights[$directory]) || $weight > $indexWeights[$directory]) {
                continue;
            }

            $findings[] = Finding::at($page->path, 1, "the weight {$weight} is not higher than that of {$directory}/_index.md, {$indexWeights[$directory]}; the section page comes first");
        }

        return $findings;
    }
}
