<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Support;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
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
 * The JSON form of the result of the test-only query probe.agent_cards, as the generated codec of a
 * result writes one (GUARDRAILS 2.2): `cards`, each with its entry, node and type, the owner's
 * fields under `fields` and each extender's under `ext`, a group as an object of its nested fields
 * and a repeated group as a list of them. It writes the fields the result holds: the query pipeline
 * already left out every field the reader may not see.
 *
 * @implements JsonCodec<ProbeCards>
 */
final readonly class AgentCardsCodec implements JsonCodec
{
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "The result of probe.agent_cards, version 1",
          "type": "object",
          "additionalProperties": false,
          "required": ["cards"],
          "properties": {
            "cards": {
              "type": "array",
              "items": {
                "type": "object",
                "additionalProperties": false,
                "required": ["entry", "ext", "fields", "node", "type"],
                "properties": {
                  "entry": {"type": "string"},
                  "ext": {"type": "object"},
                  "fields": {"type": "object"},
                  "node": {"type": "string"},
                  "type": {"type": "string"}
                }
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
        $cards = [];

        foreach ($dto->cards as $card) {
            $ext = new stdClass;

            foreach ($card->fields->extensions as $extension) {
                $ext->{$extension->namespace->value} = $this->map($extension->fields);
            }

            $json = new stdClass;
            $json->entry = $card->entry->toString();
            $json->ext = $ext;
            $json->fields = $this->map($card->fields->own);
            $json->node = $card->node->toString();
            $json->type = $card->type->toString();
            $cards[] = $json;
        }

        $result = new stdClass;
        $result->cards = $cards;

        return JsonText::encode($result);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ProbeCards
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['cards']);

        return new ProbeCards(JsonValues::required($object, 'cards', null, fn (mixed $value, FieldPath $at): array => JsonValues::list($value, $at, $this->card(...))));
    }

    private function card(mixed $value, FieldPath $at): ReadContent
    {
        $card = JsonValues::object($value, $at, ['entry', 'ext', 'fields', 'node', 'type']);
        $extensions = [];

        foreach (get_object_vars(JsonValues::required($card, 'ext', $at, static fn (mixed $ext, FieldPath $path): stdClass => $ext instanceof stdClass ? $ext : throw DecodingFailed::invalid($path, 'is not an object'))) as $namespace => $fields) {
            $extensions[] = new ExtensionFields(new FieldNamespace((string) $namespace), $this->fields($fields, $at->then('ext', (string) $namespace)));
        }

        return new ReadContent(
            JsonValues::required($card, 'entry', $at, static fn (mixed $id, FieldPath $path): EntryId => JsonValues::id($id, $path, EntryId::fromString(...))),
            JsonValues::required($card, 'node', $at, static fn (mixed $id, FieldPath $path): NodeId => JsonValues::id($id, $path, NodeId::fromString(...))),
            JsonValues::required($card, 'type', $at, static fn (mixed $id, FieldPath $path): TypeId => JsonValues::id($id, $path, TypeId::fromString(...))),
            new FieldValues(JsonValues::required($card, 'fields', $at, $this->fields(...)), ...$extensions),
        );
    }

    private function fields(mixed $value, FieldPath $at): FieldMap
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

    private function map(FieldMap $fields): stdClass
    {
        $json = new stdClass;

        foreach ($fields->fields as $field) {
            $json->{$field->handle->value} = $this->value($field->value);
        }

        return $json;
    }

    private function value(FieldValue $value): mixed
    {
        return match (true) {
            $value instanceof GroupValue => $this->map($value->fields),
            $value instanceof ListValue => array_map($this->value(...), $value->items),
            default => JsonValues::encodeFieldValue($value),
        };
    }
}
