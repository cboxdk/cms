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
     * @param  list<Page>  $pages  the Markdown files below packages/<package>/docs and packages/<package>/resources/schemas
     * @param  list<PhpFile>  $examples  the PHP files below examples
     */
    public function __construct(
        public array $sources,
        public array $schemas,
        public array $pages,
        public array $examples,
        public GateSuites $suites,
        public RepositoryFiles $files,
    ) {}
}
