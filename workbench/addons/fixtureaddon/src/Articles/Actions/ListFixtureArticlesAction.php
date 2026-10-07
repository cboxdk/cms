<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Override;
use Workbench\FixtureAddon\Articles\Domain\Dto\ArticleList;
use Workbench\FixtureAddon\Articles\Domain\FixtureArticles;
use Workbench\FixtureAddon\Articles\Domain\Queries\ListFixtureArticles;

/**
 * fixtureaddon.articles (PRD 13.4): the articles of app:fixture_article with the addon's slugs,
 * read through FixtureArticles inside the read transaction, so the viewer sees the rows row level
 * security lets them see. It is exposed on REST and Inertia, so the panel reads it as the data of
 * the addon's page and section, and REST answers the same document. It costs the rows it may
 * return.
 *
 * @implements QueryAction<ListFixtureArticles, ArticleList>
 */
#[Action(handles: ListFixtureArticles::class, surfaces: [Surface::Rest, Surface::Inertia])]
final readonly class ListFixtureArticlesAction implements QueryAction
{
    public function __construct(private FixtureArticles $articles) {}

    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(ArticleList::LIMIT);
    }

    #[Override]
    public function handle(Query $query): ArticleList
    {
        return new ArticleList($this->articles->articles(ArticleList::LIMIT), $this->articles->count());
    }
}
