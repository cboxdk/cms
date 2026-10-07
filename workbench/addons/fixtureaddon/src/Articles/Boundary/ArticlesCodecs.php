<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Boundary;

use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;

/**
 * The QueryCodec of fixtureaddon.articles, version 1, which the addon's provider registers under
 * QueryCodecs::TAG, so the query pipeline reads the query and writes its result with it, cms:build
 * describes the query on REST, and the panel's build checks the addon's contributions that read it
 * against its schemas.
 */
final readonly class ArticlesCodecs
{
    public const string QUERY = 'fixtureaddon.articles';

    public const int VERSION = 1;

    private function __construct() {}

    public static function articles(): QueryCodec
    {
        return new QueryCodec(
            new CommandName(self::QUERY),
            self::VERSION,
            new ListFixtureArticlesCodec,
            new JsonSchema(ListFixtureArticlesCodec::SCHEMA),
            new ArticleListCodec,
            new JsonSchema(ArticleListCodec::SCHEMA),
        );
    }
}
