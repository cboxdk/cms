<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Boundary;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Override;
use stdClass;
use Workbench\FixtureAddon\Articles\Domain\Queries\ListFixtureArticles;

/**
 * The JSON form of the query fixtureaddon.articles, contract version 1 (GUARDRAILS 2.2): an object
 * without members, as a query of a page and a section takes no input. An addon writes its codecs
 * by hand or with cms:generate from its schema; this one is written by hand, with the schema it
 * reads beside it, as the kernel's generated codecs carry theirs.
 *
 * @implements JsonCodec<ListFixtureArticles>
 */
final readonly class ListFixtureArticlesCodec implements JsonCodec
{
    /** The JSON Schema of the query's document, which cms:build describes the query on REST with. */
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "fixtureaddon.articles, contract version 1",
          "description": "The articles of app:fixture_article with the slug the fixture addon gives each (PRD 13.4): a query without input. It needs a role whose permissions name fixtureaddon.articles. The PHP form is Workbench\\FixtureAddon\\Articles\\Domain\\Queries\\ListFixtureArticles. Its result is the document of ArticleListCodec.",
          "type": "object",
          "additionalProperties": false,
          "properties": {}
        }
        JSON;

    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        return JsonText::encode(new stdClass);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ListFixtureArticles
    {
        JsonValues::object(JsonText::decode($json), null, []);

        return new ListFixtureArticles;
    }
}
