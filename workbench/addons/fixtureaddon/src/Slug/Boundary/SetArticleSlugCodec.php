<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Boundary;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Pipeline\Domain\CommandEncoder;
use InvalidArgumentException;
use Override;
use stdClass;
use Workbench\FixtureAddon\DeriveSlug;
use Workbench\FixtureAddon\Slug\Domain\ArticleSlug;
use Workbench\FixtureAddon\Slug\Domain\Commands\SetArticleSlug;

/**
 * The JSON form of the command fixtureaddon.slug.set, contract version 1 (GUARDRAILS 2.2): an
 * object with the entry's id, the version of its shared variant and the slug, no other key, as
 * the generated codec of a kernel command reads and writes one. The schema beside it is what
 * cms:build describes the command on REST with, what the panel's generic command form renders,
 * and what cms:panel:types types the document on. A refused document throws DecodingFailed with
 * the path of the value. It is a CommandEncoder too, as every generated command codec is, so the
 * kernel takes the idempotency content hash over the canonical JSON it writes (PRD 6.1).
 *
 * @implements JsonCodec<SetArticleSlug>
 */
final readonly class SetArticleSlugCodec implements CommandEncoder, JsonCodec
{
    /** The JSON Schema of the document. */
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "fixtureaddon.slug.set, contract version 1",
          "description": "Sets the fixture addon's slug of an article of app:fixture_article, ext.fixtureaddon.fixture_slug, in a new revision of the entry's shared variant that keeps every other field, and moves the head to it (PRD 13.4). It needs a role whose permissions name fixtureaddon.slug.set. A variant at another version than the one given is version_conflict. The PHP form is Workbench\\FixtureAddon\\Slug\\Domain\\Commands\\SetArticleSlug.",
          "type": "object",
          "additionalProperties": false,
          "required": ["entry", "slug", "version"],
          "properties": {
            "entry": {"description": "The id of the article's entry.", "$ref": "#/$defs/id"},
            "slug": {"description": "The slug: lowercase letters and digits in runs joined by single hyphens, such as a-quiet-week, at most 120 characters.", "type": "string", "pattern": "^[a-z0-9]+(-[a-z0-9]+)*$", "minLength": 1, "maxLength": 120, "examples": ["a-quiet-week"]},
            "version": {"description": "The version of the entry's shared variant the caller saw, which every save and release raises.", "type": "integer", "minimum": 1}
          },
          "$defs": {
            "id": {
              "description": "A UUIDv7, such as 0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01.",
              "type": "string",
              "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$",
              "examples": ["0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"]
            }
          }
        }
        JSON;

    /**
     * @param  SetArticleSlug  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->entry = $dto->entry->toString();
        $json->slug = $dto->slug->value;
        $json->version = $dto->version->value;

        return JsonText::encode($json);
    }

    /**
     * @throws EncodingFailed when the command is not a SetArticleSlug
     */
    #[Override]
    public function encodeCommand(Command $command): string
    {
        if (! $command instanceof SetArticleSlug) {
            throw EncodingFailed::because(sprintf('%s is not a SetArticleSlug', $command::class));
        }

        return $this->encode($command, ClassificationAccess::Sensitive);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): SetArticleSlug
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['entry', 'slug', 'version']);

        return new SetArticleSlug(
            JsonValues::required($object, 'entry', null, static fn (mixed $value, FieldPath $at): EntryId => JsonValues::id($value, $at, EntryId::fromString(...))),
            JsonValues::required($object, 'version', null, static fn (mixed $value, FieldPath $at): AggregateVersion => JsonValues::integerValue($value, $at, static fn (int $version): AggregateVersion => new AggregateVersion($version), 1)),
            JsonValues::required($object, 'slug', null, static function (mixed $value, FieldPath $at): ArticleSlug {
                $text = JsonValues::text($value, $at, 1, DeriveSlug::MAX_LENGTH);

                try {
                    return new ArticleSlug($text);
                } catch (InvalidArgumentException $exception) {
                    throw DecodingFailed::invalid($at, 'is not a well-formed slug: '.$exception->getMessage(), $exception);
                }
            }),
        );
    }
}
