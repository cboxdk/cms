<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Override;
use stdClass;

/**
 * The JSON form of the test-only command tally.add, for the tests of the surfaces and the panel,
 * as the generated codec of a command reads and writes one (GUARDRAILS 2.2): an object with the
 * tally's id, the amount, 1 unless given, and the next tally, null unless given, no other key. A
 * refused document throws DecodingFailed with the path of the value.
 *
 * @implements JsonCodec<AddTally>
 */
final readonly class AddTallyCodec implements JsonCodec
{
    /** The JSON Schema of the document, as a command's codec carries it for the OpenAPI document and the panel's build. */
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "tally.add, version 1",
          "description": "Raises a tally by an amount, creating it when it does not exist.",
          "type": "object",
          "additionalProperties": false,
          "required": ["tally"],
          "properties": {
            "amount": {"description": "How much the tally is raised by.", "type": "integer", "minimum": 1, "default": 1},
            "next": {"description": "A second tally raised by the same amount in the same changeset, or null.", "anyOf": [{"$ref": "#/$defs/id"}, {"type": "null"}], "default": null},
            "tally": {"description": "The tally's id.", "$ref": "#/$defs/id"}
          },
          "$defs": {
            "id": {
              "type": "string",
              "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$",
              "examples": ["0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"]
            }
          }
        }
        JSON;

    /**
     * @param  AddTally  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->amount = $dto->amount;
        $json->next = $dto->next?->toString();
        $json->tally = $dto->tally->toString();

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): AddTally
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['amount', 'next', 'tally']);

        return new AddTally(
            JsonValues::required($object, 'tally', null, static fn (mixed $value, FieldPath $at): TallyId => JsonValues::id($value, $at, TallyId::fromString(...))),
            JsonValues::defaulted($object, 'amount', null, static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at, min: 1), 1),
            JsonValues::defaultedNullable($object, 'next', null, static fn (mixed $value, FieldPath $at): TallyId => JsonValues::id($value, $at, TallyId::fromString(...)), null),
        );
    }
}
