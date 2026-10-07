<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Boundary;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Override;
use stdClass;
use Workbench\FixtureAddon\Articles\Domain\Dto\ArticleList;
use Workbench\FixtureAddon\Articles\Domain\Dto\ArticleSummary;

/**
 * The JSON form of the result of fixtureaddon.articles, contract version 1 (GUARDRAILS 2.2, PRD
 * 12.2): the count, and the articles, each with its entry id, its slug and its title. The title
 * is classified internal, as the schema says with x-cms-classification: it is written only for a
 * reader whose classification access allows internal, and the fixture addon's manifest reads
 * public, so a contribution of the addon that reads this query is never handed a title whatever
 * the viewer may read, while REST answers the same viewer the titles. That is the reads cap of the
 * panel extension architecture (section 3.2), held by the addon's browser tests.
 *
 * @implements JsonCodec<ArticleList>
 */
final readonly class ArticleListCodec implements JsonCodec
{
    /** The JSON Schema of the result, which cms:build describes the query on REST with. */
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "fixtureaddon.articles result, contract version 1",
          "description": "The articles of app:fixture_article with the fixture addon's slugs, in the order of their entry ids, at most 50 of them, and how many there are in all. A title is classified internal, so a reader whose access is public gets the articles without their titles. The PHP form is Workbench\\FixtureAddon\\Articles\\Domain\\Dto\\ArticleList.",
          "type": "object",
          "additionalProperties": false,
          "required": ["articles", "count"],
          "properties": {
            "articles": {
              "description": "The articles, each once, in the order of their entry ids.",
              "type": "array",
              "maxItems": 50,
              "items": {"$ref": "#/$defs/article"}
            },
            "count": {"description": "How many articles there are in all.", "type": "integer", "minimum": 0}
          },
          "$defs": {
            "article": {
              "type": "object",
              "additionalProperties": false,
              "required": ["entry", "slug"],
              "properties": {
                "entry": {"description": "The entry's id.", "type": "string", "pattern": "^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$", "examples": ["0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"]},
                "slug": {"description": "The addon's slug, ext.fixtureaddon.fixture_slug, or null where it is not set.", "type": ["string", "null"], "maxLength": 120},
                "title": {"description": "The owner's title, for a reader whose classification access allows internal.", "type": "string", "maxLength": 255, "x-cms-classification": "internal"}
              }
            }
          }
        }
        JSON;

    /**
     * @param  ArticleList  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->articles = array_map(static function (ArticleSummary $article) use ($access): stdClass {
            $item = new stdClass;
            $item->entry = $article->entry->toString();
            $item->slug = $article->slug;

            if ($access->allows(ClassificationAccess::Internal)) {
                $item->title = $article->title;
            }

            return $item;
        }, $dto->articles);
        $json->count = $dto->count;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ArticleList
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['articles', 'count']);

        return new ArticleList(
            JsonValues::required($object, 'articles', null, static fn (mixed $value, FieldPath $at): array => JsonValues::list($value, $at, static function (mixed $item, FieldPath $at) use ($access): ArticleSummary {
                $article = JsonValues::object($item, $at, ['entry', 'slug', 'title']);
                $title = JsonValues::requiredClassified($article, 'title', $at, ClassificationAccess::Internal, $access, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at, maxLength: 255));
                $slug = JsonValues::nullable($article, 'slug', $at, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at, maxLength: 120));

                return new ArticleSummary(
                    JsonValues::required($article, 'entry', $at, static fn (mixed $value, FieldPath $at): EntryId => JsonValues::id($value, $at, EntryId::fromString(...))),
                    is_string($title) ? $title : '',
                    is_string($slug) ? $slug : null,
                );
            }, maxItems: ArticleList::LIMIT)),
            JsonValues::required($object, 'count', null, static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at, 0)),
        );
    }
}
