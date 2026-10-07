<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The fixture addon's query fixtureaddon.articles, version 1 (PRD 13.4): the articles of the type
 * the addon extends, app:fixture_article, with the slug the addon gives each. It takes no input,
 * so a page of the addon and a slot fill can read it as their data; the panel runs it as the
 * viewer through the query pipeline, and REST serves it at GET /v1/queries/fixtureaddon.articles/v1
 * with the same credential. It needs a role whose permissions name fixtureaddon.articles.
 */
#[QueryName('fixtureaddon.articles', version: 1)]
final readonly class ListFixtureArticles implements Query {}
