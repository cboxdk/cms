<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Override;
use stdClass;

/**
 * The JSON form of the registry's fixture command fixture.note.create, which the fixture root
 * Valid exposes on REST: an object with its title, 1 to 200 characters.
 *
 * @implements JsonCodec<CreateNote>
 */
final readonly class CreateNoteCodec implements JsonCodec
{
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "fixture.note.create, version 1",
          "type": "object",
          "additionalProperties": false,
          "required": ["title"],
          "properties": {
            "title": {"$ref": "#/$defs/title"}
          },
          "$defs": {
            "title": {"type": "string", "minLength": 1, "maxLength": 200}
          }
        }
        JSON;

    public static function codecs(): CommandCodecs
    {
        return new CommandCodecs(self::commandCodec());
    }

    public static function commandCodec(): CommandCodec
    {
        return new CommandCodec(new CommandName('fixture.note.create'), 1, new self, new JsonSchema(self::SCHEMA));
    }

    /**
     * @param  CreateNote  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->title = $dto->title;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): CreateNote
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['title']);

        return new CreateNote(JsonValues::required($object, 'title', null, static function (mixed $value, FieldPath $at): string {
            if (! is_string($value) || $value === '' || mb_strlen($value) > 200) {
                throw DecodingFailed::invalid($at, 'is not a text of 1 to 200 characters');
            }

            return $value;
        }));
    }
}
