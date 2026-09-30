<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Pipeline\Boundary\FieldValuesJson;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Override;
use stdClass;

/**
 * The JSON form of entry.create, version 1 (GUARDRAILS 2.2, PRD 5.4): an object with the entry's
 * id, its type's id and its home node's id, each a UUIDv7, and the fields of its shared variant in
 * the form of FieldValuesJson, every key required and no other key. Every exposed surface reads the
 * command with it, and cms:build describes its REST route with SCHEMA (PRD 8.8). The fields are
 * checked against the type's schema at the write stage, not here, because the command works for
 * any type (GUARDRAILS 2.4).
 *
 * @implements JsonCodec<CreateEntry>
 */
#[Internal]
final readonly class CreateEntryCodec implements JsonCodec
{
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "entry.create, version 1",
          "description": "Creates an entry of any type with the first revision of its shared variant. The caller makes the entry's id; a create of an id that exists is version_conflict.",
          "type": "object",
          "additionalProperties": false,
          "required": ["entry", "fields", "home", "type"],
          "properties": {
            "entry": {"description": "The new entry's id.", "$ref": "#/$defs/id"},
            "fields": {"$ref": "#/$defs/fields"},
            "home": {"description": "The id of the node that owns the entry's content.", "$ref": "#/$defs/id"},
            "type": {"description": "The id of the entry's type.", "$ref": "#/$defs/id"}
          },
          "$defs": {
            "id": {
              "description": "A UUIDv7.",
              "type": "string",
              "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
            },
        JSON
        .FieldValuesJson::SCHEMA_DEFS.<<<'JSON'

          }
        }
        JSON;

    /** The command and version this codec reads. */
    public static function commandCodec(): CommandCodec
    {
        return new CommandCodec(new CommandName('entry.create'), 1, new self, new JsonSchema(self::SCHEMA));
    }

    /**
     * @param  CreateEntry  $dto
     *
     * @throws EncodingFailed when a field holds text that is not UTF-8
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->entry = $dto->entry->toString();
        $json->fields = FieldValuesJson::write($dto->fields);
        $json->home = $dto->home->toString();
        $json->type = $dto->type->toString();

        return JsonText::encode($json);
    }

    /**
     * @throws DecodingFailed with json_malformed or json_invalid
     */
    #[Override]
    public function decode(string $json, ClassificationAccess $access): CreateEntry
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['entry', 'fields', 'home', 'type']);

        return new CreateEntry(
            JsonValues::required($object, 'entry', null, static fn (mixed $value, FieldPath $at): EntryId => JsonValues::id($value, $at, EntryId::fromString(...))),
            JsonValues::required($object, 'type', null, static fn (mixed $value, FieldPath $at): TypeId => JsonValues::id($value, $at, TypeId::fromString(...))),
            JsonValues::required($object, 'home', null, static fn (mixed $value, FieldPath $at): NodeId => JsonValues::id($value, $at, NodeId::fromString(...))),
            JsonValues::required($object, 'fields', null, static fn (mixed $value, FieldPath $at): FieldValues => FieldValuesJson::read($value, $at)),
        );
    }
}
