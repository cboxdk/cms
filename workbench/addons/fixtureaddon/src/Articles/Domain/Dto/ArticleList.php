<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Domain\Dto;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of fixtureaddon.articles: the articles in the order of their entry ids, at most
 * ArticleList::LIMIT of them, and how many there are in all.
 */
final readonly class ArticleList implements Result
{
    /** The most articles the result lists. */
    public const int LIMIT = 50;

    /**
     * @param  list<ArticleSummary>  $articles
     */
    public function __construct(
        public array $articles,
        public int $count,
    ) {}
}
