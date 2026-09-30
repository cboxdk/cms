<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\InvalidFieldValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Override;
use stdClass;

/**
 * The JSON form of the test-only command probe.rename, for the surface tests, as the generated
 * codec of a command reads and writes one (GUARDRAILS 2.2): an object with the entry, type and
 * home node ids and the owner's fields by handle, every key required and no other key. It keeps no
 * expected versions, so a decoded command expects none. A refused document throws DecodingFailed
 * with the path of the value, such as fields.label.
 *
 * @implements JsonCodec<RenameProbe>
 */
final readonly class RenameProbeCodec implements JsonCodec
{
    /** The JSON Schema of the document, as a command's codec carries it for the OpenAPI document. */
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "probe.rename, version 1",
          "type": "object",
          "additionalProperties": false,
          "required": ["entry", "fields", "home", "type"],
          "properties": {
            "entry": {"$ref": "#/$defs/id"},
            "fields": {
              "description": "The owner's fields by handle.",
              "type": "object",
              "propertyNames": {"pattern": "^[a-z][a-z0-9_]*$"}
            },
            "home": {"$ref": "#/$defs/id"},
            "type": {"$ref": "#/$defs/id"}
          },
          "$defs": {
            "id": {
              "type": "string",
              "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
            }
          }
        }
        JSON;

    /**
     * @param  RenameProbe  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $fields = new stdClass;

        foreach ($dto->fields->own->fields as $field) {
            $fields->{$field->handle->value} = JsonValues::encodeFieldValue($field->value);
        }

        $json = new stdClass;
        $json->entry = $dto->entry->toString();
        $json->fields = $fields;
        $json->home = $dto->home->toString();
        $json->type = $dto->type->toString();

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): RenameProbe
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['entry', 'fields', 'home', 'type']);

        return new RenameProbe(
            JsonValues::required($object, 'entry', null, static fn (mixed $value, FieldPath $at): EntryId => JsonValues::id($value, $at, EntryId::fromString(...))),
            JsonValues::required($object, 'type', null, static fn (mixed $value, FieldPath $at): TypeId => JsonValues::id($value, $at, TypeId::fromString(...))),
            JsonValues::required($object, 'home', null, static fn (mixed $value, FieldPath $at): NodeId => JsonValues::id($value, $at, NodeId::fromString(...))),
            JsonValues::required($object, 'fields', null, $this->fields(...)),
        );
    }

    private function fields(mixed $value, FieldPath $at): FieldValues
    {
        if (! $value instanceof stdClass) {
            throw DecodingFailed::invalid($at, 'is not an object');
        }

        $fields = [];

        foreach (get_object_vars($value) as $handle => $field) {
            $path = $at->then((string) $handle);

            try {
                $fields[] = new NamedValue(new FieldHandle((string) $handle), JsonValues::fieldValue($field, $path));
            } catch (InvalidFieldValue $invalid) {
                throw DecodingFailed::invalid($path, 'is not a field handle', $invalid);
            }
        }

        return new FieldValues(new FieldMap(...$fields));
    }
}
