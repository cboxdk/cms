<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest\Support;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCards;
use Override;
use stdClass;

/**
 * The JSON form of the result of the test-only query probe.cards: its cards, each with its entry,
 * node and type ids, the owner's fields by handle and the extenders' fields below ext by
 * namespace. The query pipeline has stripped every field above the caller's classification access
 * before the codec sees the result.
 *
 * @implements JsonCodec<ProbeCards>
 */
final readonly class ProbeCardsCodec implements JsonCodec
{
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "The result of probe.cards, version 1",
          "type": "object",
          "additionalProperties": false,
          "required": ["cards"],
          "properties": {
            "cards": {"type": "array", "items": {"$ref": "#/$defs/card"}}
          },
          "$defs": {
            "card": {
              "type": "object",
              "additionalProperties": false,
              "required": ["entry", "ext", "fields", "node", "type"],
              "properties": {
                "entry": {"type": "string"},
                "ext": {"type": "object", "additionalProperties": {"type": "object"}},
                "fields": {"type": "object"},
                "node": {"type": "string"},
                "type": {"type": "string"}
              }
            }
          }
        }
        JSON;

    /**
     * @param  ProbeCards  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->cards = array_map(static function (ReadContent $card): stdClass {
            $ext = new stdClass;

            foreach ($card->fields->extensions as $extension) {
                $ext->{$extension->namespace->value} = self::map($extension->fields);
            }

            $object = new stdClass;
            $object->entry = $card->entry->toString();
            $object->ext = $ext;
            $object->fields = self::map($card->fields->own);
            $object->node = $card->node->toString();
            $object->type = $card->type->toString();

            return $object;
        }, $dto->cards);

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ProbeCards
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['cards']);

        return new ProbeCards(JsonValues::required($object, 'cards', null, static fn (mixed $value, FieldPath $at): array => JsonValues::list($value, $at, static function (mixed $item, FieldPath $at): ReadContent {
            $card = JsonValues::object($item, $at, ['entry', 'ext', 'fields', 'node', 'type']);
            $extensions = JsonValues::required($card, 'ext', $at, static function (mixed $value, FieldPath $at): array {
                if (! $value instanceof stdClass) {
                    throw DecodingFailed::invalid($at, 'is not an object');
                }

                $extensions = [];

                foreach (get_object_vars($value) as $namespace => $fields) {
                    $extensions[] = new ExtensionFields(new FieldNamespace((string) $namespace), self::fields($fields, $at->then((string) $namespace)));
                }

                return $extensions;
            });

            return new ReadContent(
                JsonValues::required($card, 'entry', $at, static fn (mixed $value, FieldPath $at): EntryId => JsonValues::id($value, $at, EntryId::fromString(...))),
                JsonValues::required($card, 'node', $at, static fn (mixed $value, FieldPath $at): NodeId => JsonValues::id($value, $at, NodeId::fromString(...))),
                JsonValues::required($card, 'type', $at, static fn (mixed $value, FieldPath $at): TypeId => JsonValues::id($value, $at, TypeId::fromString(...))),
                new FieldValues(JsonValues::required($card, 'fields', $at, self::fields(...)), ...$extensions),
            );
        })));
    }

    private static function map(FieldMap $fields): stdClass
    {
        $object = new stdClass;

        foreach ($fields->fields as $field) {
            $object->{$field->handle->value} = JsonValues::encodeFieldValue($field->value);
        }

        return $object;
    }

    private static function fields(mixed $value, FieldPath $at): FieldMap
    {
        if (! $value instanceof stdClass) {
            throw DecodingFailed::invalid($at, 'is not an object');
        }

        $fields = [];

        foreach (get_object_vars($value) as $handle => $field) {
            $fields[] = new NamedValue(new FieldHandle((string) $handle), JsonValues::fieldValue($field, $at->then((string) $handle)));
        }

        return new FieldMap(...$fields);
    }
}
