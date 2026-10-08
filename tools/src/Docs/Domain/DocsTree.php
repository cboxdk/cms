<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Everything the documentation check reads from a tree, which is the repository or a scratch copy.
 */
final readonly class DocsTree
{
    /**
     * @param  list<PhpFile>  $sources  the PHP files below packages/<package>/src
     * @param  list<string>  $schemas  the repo-relative *.json files below packages/<package>/resources/schemas
     * @param  list<Page>  $pages  the Markdown files below docs/
     * @param  list<PhpFile>  $examples  the PHP files below examples
     * @param  list<Page>  $rootPages  the Markdown files of DocsLayout::ROOT_MARKDOWN at the root that exist, README.md first, whose links are checked like a page's
     * @param  list<string>  $docsFiles  the repo-relative path of every file below docs/, Markdown or not
     * @param  list<string>  $docsDirectories  the repo-relative path of every directory below docs/, docs/ itself not included
     * @param  list<string>  $strayPages  the repo-relative *.md files below packages/, which belong in docs/
     * @param  JsSuite  $jsSuite  the JS unit suite of gate 5, the `unit` project of the root's vitest.config.ts
     * @param  list<string>  $jsExamples  the repo-relative Vitest test files below examples (JsSuite::SUFFIXES)
     */
    public function __construct(
        public array $sources,
        public array $schemas,
        public array $pages,
        public array $examples,
        public GateSuites $suites,
        public RepositoryFiles $files,
        public array $rootPages,
        public array $docsFiles,
        public array $docsDirectories,
        public array $strayPages,
        public JsSuite $jsSuite,
        public array $jsExamples,
    ) {}
}
