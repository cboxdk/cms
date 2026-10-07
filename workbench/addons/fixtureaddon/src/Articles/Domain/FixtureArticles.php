<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Domain;

use Workbench\FixtureAddon\Articles\Domain\Dto\ArticleSummary;

/**
 * Where the fixture addon reads its articles from: the type table of app:fixture_article, as the
 * actor of the read, so row level security decides which rows it sees.
 */
interface FixtureArticles
{
    /**
     * The articles in the order of their entry ids, at most $limit of them, each entry once: its
     * pending draft where one differs from what was released, else the released row.
     *
     * @return list<ArticleSummary>
     */
    public function articles(int $limit): array;

    /**
     * How many articles there are in all.
     */
    public function count(): int;
}
