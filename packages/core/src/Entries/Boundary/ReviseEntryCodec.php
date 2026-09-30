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
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Pipeline\Boundary\FieldValuesJson;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Override;
use stdClass;

/**
 * The JSON form of entry.revise, version 1 (GUARDRAILS 2.2, PRD 5.4): an object with the entry's
 * id, a UUIDv7, the version of its shared variant the caller saw, an integer from 1, and all the
 * fields of the variant in the form of FieldValuesJson, every key required and no other key. Every
 * exposed surface reads the command with it, and cms:build describes its REST route with SCHEMA
 * (PRD 8.8). The fields are checked against the type's schema at the write stage, not here,
 * because the command works for any type (GUARDRAILS 2.4).
 *
 * @implements JsonCodec<ReviseEntry>
 */
#[Internal]
final readonly class ReviseEntryCodec implements JsonCodec
{
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "entry.revise, version 1",
          "description": "Writes the next revision of an entry's shared variant, a whole snapshot of its fields. A variant at another version than the one given, or an entry that does not exist or the caller cannot reach, is version_conflict.",
          "type": "object",
          "additionalProperties": false,
          "required": ["entry", "fields", "version"],
          "properties": {
            "entry": {"description": "The entry's id.", "$ref": "#/$defs/id"},
            "fields": {"$ref": "#/$defs/fields"},
            "version": {"description": "The version of the shared variant the caller saw.", "type": "integer", "minimum": 1}
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
        return new CommandCodec(new CommandName('entry.revise'), 1, new self, new JsonSchema(self::SCHEMA));
    }

    /**
     * @param  ReviseEntry  $dto
     *
     * @throws EncodingFailed when a field holds text that is not UTF-8
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->entry = $dto->entry->toString();
        $json->fields = FieldValuesJson::write($dto->fields);
        $json->version = $dto->version->value;

        return JsonText::encode($json);
    }

    /**
     * @throws DecodingFailed with json_malformed or json_invalid
     */
    #[Override]
    public function decode(string $json, ClassificationAccess $access): ReviseEntry
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['entry', 'fields', 'version']);

        return new ReviseEntry(
            JsonValues::required($object, 'entry', null, static fn (mixed $value, FieldPath $at): EntryId => JsonValues::id($value, $at, EntryId::fromString(...))),
            JsonValues::required($object, 'version', null, static fn (mixed $value, FieldPath $at): AggregateVersion => new AggregateVersion(JsonValues::integer($value, $at, 1))),
            JsonValues::required($object, 'fields', null, static fn (mixed $value, FieldPath $at): FieldValues => FieldValuesJson::read($value, $at)),
        );
    }
}
