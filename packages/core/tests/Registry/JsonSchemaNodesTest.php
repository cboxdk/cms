<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Registry\Boundary\JsonSchemaNodes;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaNode;
use Cbox\Cms\Core\Registry\Domain\JsonKind;
use JsonException;

/*
 * What cms:build knows of a contract's JSON Schema when it checks the panel's contributions
 * (PRD 13.4): the kinds each place takes, its members and items, through references and unions,
 * and whether a path or a JSON pointer exists and fits another place.
 */

function schemaNode(string $json): SchemaNode
{
    return JsonSchemaNodes::read($json);
}

/**
 * @return list<string>
 */
function schemaKinds(?SchemaNode $node): array
{
    return array_map(static fn (JsonKind $kind): string => $kind->value, $node->kinds ?? []);
}

it('reads the kinds from type, enum and const, and every kind from a schema that does not narrow', function (string $json, array $kinds): void {
    expect(schemaKinds(schemaNode($json)))->toBe($kinds);
})->with([
    'a type' => ['{"type": "string"}', ['string']],
    'a list of types' => ['{"type": ["string", "null"]}', ['null', 'string']],
    'an enum' => ['{"enum": ["a", 1, null]}', ['integer', 'null', 'string']],
    'a const' => ['{"const": true}', ['boolean']],
    'nothing' => ['{}', ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string']],
    'properties without a type' => ['{"properties": {"a": {}}}', ['object']],
]);

it('follows references and unions, and walks members, items and pointers', function (): void {
    $node = schemaNode('{"type": "object", "additionalProperties": false, "required": ["note"], "properties": {"note": {"$ref": "#/$defs/note"}, "tags": {"type": "array", "items": {"type": "string"}}, "either": {"anyOf": [{"type": "integer"}, {"type": "null"}]}, "fields": {"type": "object", "additionalProperties": {"type": "boolean"}}}, "$defs": {"note": {"type": "string"}}}');

    expect($node->closed)->toBeTrue()
        ->and($node->required)->toBe(['note'])
        ->and(schemaKinds($node->member('note')))->toBe(['string'])
        ->and($node->member('nope'))->toBeNull()
        ->and(schemaKinds($node->pointer('/tags/0')))->toBe(['string'])
        ->and(schemaKinds($node->pointer('/either')))->toBe(['integer', 'null'])
        ->and(schemaKinds($node->at(FieldPath::fromString('fields.anything'))))->toBe(['boolean'])
        ->and($node->at(FieldPath::fromString('note.deeper')))->toBeNull()
        ->and($node->pointer('/missing'))->toBeNull();
});

it('takes any member of an object that does not close itself', function (): void {
    expect(schemaKinds(schemaNode('{"type": "object", "properties": {"a": {"type": "string"}}}')->member('b')))->toBe(['array', 'boolean', 'integer', 'null', 'number', 'object', 'string']);
});

it('tells whether every value of one place fits another, an integer as a number', function (string $from, string $into, bool $fits): void {
    expect(schemaNode($from)->fitsInto(schemaNode($into)))->toBe($fits);
})->with([
    'a string into a string or null' => ['{"type": "string"}', '{"type": ["string", "null"]}', true],
    'a string or null into a string' => ['{"type": ["string", "null"]}', '{"type": "string"}', false],
    'an integer into a number' => ['{"type": "integer"}', '{"type": "number"}', true],
    'a number into an integer' => ['{"type": "number"}', '{"type": "integer"}', false],
    'anything into anything' => ['{}', '{}', true],
]);

it('reads a schema that refers to itself to a fixed depth', function (): void {
    $node = schemaNode('{"$ref": "#/$defs/value", "$defs": {"value": {"type": ["array", "string"], "items": {"$ref": "#/$defs/value"}}}}');

    expect(schemaKinds($node->pointer(str_repeat('/0', 40))))->not->toBe([]);
});

it('refuses text that is not JSON', function (): void {
    expect(static fn (): SchemaNode => schemaNode('{'))->toThrow(JsonException::class);
});
