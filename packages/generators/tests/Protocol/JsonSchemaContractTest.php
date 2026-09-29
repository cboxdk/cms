<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Boundary\JsonSchemaContract;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Step;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Box;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\ParcelCodecV1;
use Closure;
use DateTimeImmutable;
use LogicException;

/*
 * A kernel JSON Schema read into the codec contract of its version, bound to the classes of its
 * binding (GUARDRAILS 2.2): the schema gives the keys, their presence, their defaults and their
 * rules; reflection on the bound classes at build time holds each to its object; and every keyword,
 * binding or class the codec has no form for is refused with generate_schema_invalid instead of
 * being dropped.
 */

/**
 * The message of the one problem reading the probe schema, changed by $change, gives.
 *
 * @param  ?Closure(array<string, mixed>): array<string, mixed>  $change
 */
function schemaProblem(?Closure $change = null, ?SchemaBinding $binding = null, ?string $json = null): string
{
    try {
        $json === null ? ParcelSchema::contract($change, $binding) : JsonSchemaContract::read($json, $binding ?? ParcelSchema::binding(), Experimental::class);
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid]);

        return $failed->problems[0]->message;
    }

    throw new LogicException('The schema was read without a problem.');
}

/**
 * The probe binding with other values, objects or names.
 *
 * @param  array<string, ValueBinding>|null  $values
 * @param  array<string, string>|null  $objects
 * @param  array<string, string>  $names
 */
function parcelBinding(?array $values = null, ?array $objects = null, array $names = []): SchemaBinding
{
    $binding = ParcelSchema::binding();

    return new SchemaBinding($binding->schema, $binding->codecClass, $binding->version, $objects ?? $binding->objects, $values ?? $binding->values, $names);
}

/**
 * The probe schema with one property replaced.
 *
 * @param  array<string, mixed>|null  $node  the property's new schema, or null to remove it
 * @return Closure(array<string, mixed>): array<string, mixed>
 */
function withProperty(string $key, ?array $node): Closure
{
    return static fn (array $document): array => withPropertyIn($document, $key, $node);
}

/**
 * @param  array<mixed>  $schema
 * @param  array<string, mixed>|null  $node
 * @return array<string, mixed>
 */
function withPropertyIn(array $schema, string $key, ?array $node): array
{
    $document = [];

    foreach ($schema as $name => $value) {
        $document[(string) $name] = $value;
    }

    /** @var array<string, mixed> $properties */
    $properties = $document['properties'];

    if ($node === null) {
        unset($properties[$key]);
    } else {
        $properties[$key] = $node;
    }

    $document['properties'] = $properties;

    return $document;
}

it('reads each key with its presence, its default and its rules', function (): void {
    $contract = ParcelSchema::contract();
    $root = $contract->root;
    $byKey = [];

    foreach ($root->properties as $property) {
        $byKey[$property->key] = $property;
    }

    $mode = static fn (CodecProperty $property): array => [$property->name, $property->value->kind, $property->required, $property->nullable, $property->default];

    expect($root->class)->toBe(Parcel::class)
        ->and($root->arguments)->toBe(['label', 'step', 'id', 'sentAt', 'owner', 'count', 'tone', 'fragile', 'note', 'tags', 'box'])
        ->and($contract->attribute)->toBe(Experimental::class)
        ->and($contract->codecClass)->toBe('ParcelCodecV1')
        ->and($contract->summary[0])->toBe('The JSON codec of Parcel, contract version 1 (GUARDRAILS 2.2): Parcel as parcel.v1.json.')
        ->and(array_map($mode, $byKey))->toBe([
            'box' => ['box', CodecKind::Object, false, false, 'new Box'],
            'count' => ['count', CodecKind::Integer, false, false, '3'],
            'fragile' => ['fragile', CodecKind::Boolean, false, false, 'false'],
            'id' => ['id', CodecKind::Id, true, true, null],
            'label' => ['label', CodecKind::Text, true, false, null],
            'note' => ['note', CodecKind::Text, false, true, 'null'],
            'owner' => ['owner', CodecKind::Object, true, true, null],
            'sent_at' => ['sentAt', CodecKind::Datetime, true, true, null],
            'step' => ['step', CodecKind::Enum, true, false, null],
            'tags' => ['tags', CodecKind::List, false, false, '[]'],
            'tone' => ['tone', CodecKind::Enum, false, false, 'Tone::Warm'],
        ])
        ->and(array_map(static fn (ValidationRule $rule): array => [$rule->name->value, $rule->arguments], $byKey['label']->value->rules))->toBe([['string', []], ['min_length', ['1']], ['max_length', ['20']]])
        ->and(array_map(static fn (ValidationRule $rule): array => [$rule->name->value, $rule->arguments], $byKey['count']->value->rules))->toBe([['integer', []], ['min', ['0']], ['max', ['9']]])
        ->and(array_map(static fn (ValidationRule $rule): array => [$rule->name->value, $rule->arguments], $byKey['tags']->value->rules))->toBe([['min_items', ['0']], ['max_items', ['4']]])
        ->and($byKey['tags']->value->item?->kind)->toBe(CodecKind::Value)
        ->and($byKey['tags']->value->item?->class)->toBe(ProjectionName::class)
        ->and($byKey['owner']->value->object?->class)->toBe(Box::class)
        ->and($byKey['box']->description)->toBe('A box.');
});

it('writes exactly the committed probe codec', function (): void {
    $file = ParcelSchema::codec();

    expect($file->path)->toBe('Fixtures/ParcelCodecV1.php')
        ->and(file_get_contents(__DIR__.'/'.$file->path))->toBe($file->contents);
});

it('round-trips the probe document through its codec, with every default for a missing key', function (): void {
    $codec = new ParcelCodecV1;
    $parcel = new Parcel(
        label: 'Books',
        step: Step::Two,
        id: ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'),
        sentAt: new DateTimeImmutable('2026-03-10T13:00:00+01:00'),
        owner: new Box(4),
        count: 9,
        tone: Tone::Cold,
        fragile: true,
        note: 'Keep dry.',
        tags: [new ProjectionName('search')],
        box: new Box(2),
    );
    $json = '{"box":{"size":2},"count":9,"fragile":true,"id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","label":"Books","note":"Keep dry.","owner":{"size":4},"sent_at":"2026-03-10T12:00:00.000000Z","step":2,"tags":["search"],"tone":"cold"}';
    $minimal = '{"id":null,"label":"Books","owner":null,"sent_at":null,"step":1}';

    expect($codec->encode($parcel, ClassificationAccess::Public))->toBe($json)
        ->and($codec->decode($json, ClassificationAccess::Public))->toEqual($parcel)
        ->and($codec->decode($minimal, ClassificationAccess::Public))->toEqual(new Parcel('Books', Step::One, null, null, null))
        ->and($codec->encode($codec->decode($minimal, ClassificationAccess::Public), ClassificationAccess::Public))
        ->toBe('{"box":{"size":1},"count":3,"fragile":false,"id":null,"label":"Books","note":null,"owner":null,"sent_at":null,"step":1,"tags":[],"tone":"warm"}')
        ->and($codec->decode('{"box":{},"id":null,"label":"Books","owner":{},"sent_at":null,"step":1}', ClassificationAccess::Public)->owner)->toEqual(new Box);
});

it('refuses what the probe schema or its classes refuse, at the value or the object', function (string $json, ?string $path, string $reason): void {
    try {
        new ParcelCodecV1()->decode($json, ClassificationAccess::Public);
    } catch (DecodingFailed $failed) {
        expect([$failed->errorCode->value, $failed->path?->toString(), $failed->reason])->toBe(['json_invalid', $path, $reason]);

        return;
    }

    throw new LogicException('The document was read.');
})->with([
    'a missing nullable key' => ['{"label":"Books","owner":null,"sent_at":null,"step":1}', 'id', 'is missing, and the field is required'],
    'a null key with a default' => ['{"count":null,"id":null,"label":"Books","owner":null,"sent_at":null,"step":1}', 'count', 'is null, and the field is not nullable'],
    'a count above its maximum' => ['{"count":10,"id":null,"label":"Books","owner":null,"sent_at":null,"step":1}', 'count', 'is 10, more than the maximum 9'],
    'a box of size 0' => ['{"id":null,"label":"Books","owner":{"size":0},"sent_at":null,"step":1}', 'owner.size', 'is 0, less than the minimum 1'],
    'a label the class refuses' => ['{"id":null,"label":"refused","owner":null,"sent_at":null,"step":1}', null, 'breaks a rule of the contract: A parcel is never labelled "refused".'],
]);

it('refuses a schema that is not a draft 2020-12 object', function (string $json, string $message): void {
    expect(schemaProblem(json: $json))->toBe('parcel.v1.json #: '.$message.'.');
})->with([
    'malformed JSON' => ['{', 'is not well-formed JSON: Syntax error'],
    'a list' => ['[]', 'is not a JSON object'],
    'another draft' => ['{"$schema":"http://json-schema.org/draft-07/schema#"}', 'has no "$schema" of JSON Schema draft 2020-12 (https://json-schema.org/draft/2020-12/schema)'],
]);

it('refuses what the codec has no form for, naming the place', function (Closure $change, string $message): void {
    expect(schemaProblem($change))->toBe('parcel.v1.json '.$message.'.');
})->with([
    'an unknown keyword' => [withProperty('label', ['type' => 'string', 'const' => 'Books']), '#/properties/label: has the keyword "const", which the codec has no form for'],
    'an unknown keyword of the document' => [static fn (array $document): array => [...$document, '$id' => 'https://example.test/parcel'], '#: has the keyword "$id", which the codec has no form for'],
    'an object that allows other keys' => [static fn (array $document): array => [...$document, 'additionalProperties' => true], '#: needs "additionalProperties": false, because the codec refuses a key the contract does not have'],
    'a document that is no object' => [static fn (array $document): array => [...$document, 'type' => 'array'], '#: is not an object with "type": "object"'],
    'an object without properties' => [static function (array $document): array {
        /** @var array<string, mixed> $definitions */
        $definitions = $document['$defs'];
        $definitions['box'] = ['type' => 'object', 'additionalProperties' => false, 'properties' => (object) []];

        return [...$document, '$defs' => $definitions];
    }, '#/properties/box: has no "properties"'],
    'a required key that is no property' => [static fn (array $document): array => [...$document, 'required' => ['id', 'label', 'owner', 'sent_at', 'step', 'weight']], '#: has a "required" that is not a list of its properties'],
    'an optional key without a default' => [withProperty('count', ['type' => 'integer']), '#/properties/count: is not required and has no default; the codec gives a missing key its default'],
    'a required key with a default' => [withProperty('label', ['type' => 'string', 'default' => 'Books']), '#/properties/label: is required and has a default; a required key has no default'],
    'a string with a pattern and no class' => [withProperty('label', ['type' => 'string', 'pattern' => '^[A-Z]']), '#/properties/label: is a string with a "pattern" that the binding binds to no class; the codec has no form for a pattern, so bind the value to a class whose constructor checks it'],
    'an enum with no class' => [withProperty('label', ['enum' => ['a', 'b']]), '#/properties/label: has an "enum" that the binding binds to no PHP enum'],
    'an integer enum with no class' => [withProperty('count', ['enum' => [1, 2], 'default' => 1]), '#/properties/count: has an "enum" that the binding binds to no PHP enum'],
    'an enum of strings and integers' => [withProperty('step', ['enum' => [1, 'two']]), '#/properties/step: has an "enum" whose values are not all strings or all integers'],
    'a format other than date-time' => [withProperty('label', ['type' => 'string', 'format' => 'email']), '#/properties/label: has a "format" other than "date-time", or a date-time with a length'],
    'a date-time with a length' => [withProperty('sent_at', ['type' => ['string', 'null'], 'format' => 'date-time', 'maxLength' => 40]), '#/properties/sent_at: has a "format" other than "date-time", or a date-time with a length'],
    'a type that is two types' => [withProperty('label', ['type' => ['string', 'integer']]), '#/properties/label: has no "type", or one that is not a type or a type and "null"'],
    'no type' => [withProperty('label', ['description' => 'A label.']), '#/properties/label: has no "type", or one that is not a type or a type and "null"'],
    'the type null' => [withProperty('label', ['type' => 'null']), '#/properties/label: has the type "null", which has no form in the codec'],
    'an array without items' => [withProperty('tags', ['type' => 'array', 'default' => []]), '#/properties/tags: is an array without "items"'],
    'items that may be null' => [withProperty('tags', ['type' => 'array', 'items' => ['type' => ['string', 'null']], 'default' => []]), '#/properties/tags/items: may be null; an item of a list is never null'],
    'a negative length' => [withProperty('label', ['type' => 'string', 'maxLength' => -1]), '#/properties/label: has a "maxLength" that is not an integer of 0 or more'],
    'a bound value with a length that is no integer' => [withProperty('id', ['type' => ['string', 'null'], 'maxLength' => '36']), '#/properties/id: has a "maxLength" that is not an integer of 0 or more'],
    'a minimum that is no integer' => [withProperty('count', ['type' => 'integer', 'minimum' => 0.5, 'default' => 3]), '#/properties/count: has a "minimum" that is not an integer of 0 or more'],
    'a reference outside $defs' => [withProperty('box', ['$ref' => 'other.json#/box', 'default' => (object) []]), '#/properties/box: has a "$ref" that is not to "#/$defs/<name>"'],
    'a reference to no definition' => [withProperty('box', ['$ref' => '#/$defs/crate', 'default' => (object) []]), '#/properties/box: refers to "#/$defs/crate", which is not a definition of the schema without a "$ref" of its own'],
    'anyOf without null' => [withProperty('owner', ['anyOf' => [['$ref' => '#/$defs/box'], ['type' => 'string']]]), '#/properties/owner: has an "anyOf" that is not a value and {"type": "null"}, the one form of a nullable reference the codec reads'],
    'anyOf of three' => [withProperty('owner', ['anyOf' => [['$ref' => '#/$defs/box'], ['type' => 'null'], ['type' => 'null']]]), '#/properties/owner: has an "anyOf" that is not a value and {"type": "null"}, the one form of a nullable reference the codec reads'],
    'anyOf of a nullable value' => [withProperty('owner', ['anyOf' => [['type' => ['integer', 'null']], ['type' => 'null']]]), '#/properties/owner: is null twice in its "anyOf"'],
    'anyOf of a value that is not a schema' => [withProperty('owner', ['anyOf' => ['box', ['type' => 'null']]]), '#/properties/owner: has an "anyOf" whose value is not a schema object'],
    'a property that is not a schema' => [withProperty('label', null), '#: has a "required" that is not a list of its properties'],
    'an enum out of order' => [withProperty('step', ['enum' => [2, 1]]), '#/properties/step: has the "enum" [2,1], but Cbox\Cms\Generators\Tests\Codec\Fixtures\Step has the values [1,2] in that order'],
    'an enum pattern a value breaks' => [withProperty('tone', ['type' => 'string', 'pattern' => '^w', 'default' => 'warm']), '#/properties/tone: has a "pattern" or "maxLength" that the value "cold" of Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone breaks'],
    'an enum length a value breaks' => [withProperty('tone', ['type' => 'string', 'pattern' => '^[a-z]+$', 'maxLength' => 3, 'default' => 'warm']), '#/properties/tone: has a "pattern" or "maxLength" that the value "warm" of Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone breaks'],
    'an enum without values or a pattern' => [withProperty('tone', ['type' => 'string', 'default' => 'warm']), '#/properties/tone: is bound to an enum and has neither an "enum" nor a "pattern" its values match'],
    'a bound value with a format' => [withProperty('id', ['type' => ['string', 'null'], 'format' => 'uuid']), '#/properties/id: is bound to a class and has a "format"'],
    'a default the value does not take' => [withProperty('count', ['type' => 'integer', 'default' => 'three']), '#/properties/count: has the default "three", which is not a value of the property or has no form in PHP'],
    'a null default of a value that is never null' => [withProperty('count', ['type' => 'integer', 'default' => null]), '#/properties/count: has the default null, which is not a value of the property or has no form in PHP'],
    'a list default with items' => [withProperty('tags', ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['search']]), '#/properties/tags: has the default ["search"], which is not a value of the property or has no form in PHP'],
    'an enum default of no case' => [withProperty('tone', ['type' => 'string', 'pattern' => '^[a-z]+$', 'default' => 'hot']), '#/properties/tone: has the default "hot", which is not a value of Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone'],
    'a default that is not the constructor\'s' => [withProperty('count', ['type' => 'integer', 'default' => 4]), '#/properties/count: has the default 4, but the default of Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel::__construct($count) is 3'],
    'a default of an object with required keys' => [static function (array $document): array {
        /** @var array<string, mixed> $definitions */
        $definitions = $document['$defs'];
        $definitions['box'] = ['type' => 'object', 'additionalProperties' => false, 'required' => ['size'], 'properties' => ['size' => ['type' => 'integer']]];

        return [...$document, '$defs' => $definitions];
    }, '#/properties/box: has the default {}, but its object has required keys'],
    'a key the constructor does not take' => [withProperty('weight', ['type' => 'integer', 'default' => 0]), '#: is bound to Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel, whose constructor takes label, step, id, sentAt, owner, count, tone, fragile, note, tags, box, but the object has the properties box, count, fragile, id, label, note, owner, sentAt, step, tags, tone, weight'],
    'a key of another type than the argument' => [withProperty('count', ['type' => 'string', 'default' => '3']), '#/properties/count: is the key "count", but Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel::__construct($count) is not of the type string'],
    'a nullable key the argument does not allow' => [withProperty('count', ['type' => ['integer', 'null'], 'default' => 3]), '#/properties/count: is the key "count", which may be null, but Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel::__construct($count) does not'],
    'a key that is never null for an argument that allows it' => [withProperty('note', ['type' => 'string', 'default' => 'none']), '#/properties/note: is the key "note", which is never null, but Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel::__construct($note) allows it'],
]);

it('refuses a binding that does not fit the schema or its classes', function (SchemaBinding $binding, string $message): void {
    expect(schemaProblem(binding: $binding))->toBe('parcel.v1.json '.$message.'.');
})->with([
    'an object without a class' => [parcelBinding(objects: ['#' => Parcel::class]), '#/properties/box: is an object that the binding binds to no class; add "#/$defs/box" to its objects'],
    'a binding of no place' => [parcelBinding(objects: [...ParcelSchema::binding()->objects, '#/$defs/crate' => Box::class]), '#/$defs/crate: is in the binding, but is no place of the schema that can be bound'],
    'a value binding of no place' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/weight' => ValueBinding::id(ChangesetId::class)]), '#/properties/weight: is in the binding, but is no place of the schema that can be bound'],
    'a name of no place' => [parcelBinding(names: ['#/properties/weight' => 'mass']), '#/properties/weight: is in the binding, but is no place of the schema that can be bound'],
    'a name the constructor does not take' => [parcelBinding(names: ['#/properties/label' => 'title']), '#: is bound to Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel, whose constructor takes label, step, id, sentAt, owner, count, tone, fragile, note, tags, box, but the object has the properties box, count, fragile, id, title, note, owner, sentAt, step, tags, tone'],
    'a class that does not exist' => [parcelBinding(objects: ['#' => 'Cbox\Cms\Generators\Tests\Protocol\Fixtures\Crate', '#/$defs/box' => Box::class]), '#: is bound to Cbox\Cms\Generators\Tests\Protocol\Fixtures\Crate, which is not a class'],
    'a value class that does not exist' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/id' => ValueBinding::id('Cbox\Cms\Generators\Tests\Protocol\Fixtures\Crate')]), '#/properties/id: is bound to Cbox\Cms\Generators\Tests\Protocol\Fixtures\Crate, which is not a class'],
    'an id without fromString' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/id' => ValueBinding::id(ProjectionName::class)]), '#/properties/id: is bound to Cbox\Cms\Contracts\Consistency\ProjectionName as an id, which has no public static fromString(string) and public toString(): string'],
    'a value without a string value' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/tags/items' => ValueBinding::value(ChangesetId::class)]), '#/properties/tags/items: is bound to Cbox\Cms\Contracts\Ids\ChangesetId as a value, which has no constructor of one string and public string $value'],
    'an enum that is not an enum' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/step' => ValueBinding::enum(Box::class)]), '#/properties/step: is bound to Cbox\Cms\Generators\Tests\Protocol\Fixtures\Box as an enum, which is not a backed enum'],
    'an integer bound to a value' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/count' => ValueBinding::value(ProjectionName::class)]), '#/properties/count: is an integer bound to a class that is not an enum'],
    'a boolean bound to a class' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/fragile' => ValueBinding::value(ProjectionName::class)]), '#/properties/fragile: is a value of the kind boolean, which the binding cannot bind to a class'],
    'a list bound to a class' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/tags' => ValueBinding::value(ProjectionName::class)]), '#/properties/tags: is a value of the kind list, which the binding cannot bind to a class'],
    'an object bound to a value class' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/box' => ValueBinding::value(ProjectionName::class)]), '#/properties/box: is a value of the kind object, which the binding cannot bind to a class'],
    'a string bound as the class of another argument' => [parcelBinding(values: [...ParcelSchema::binding()->values, '#/properties/label' => ValueBinding::value(ProjectionName::class)]), '#/properties/label: is the key "label", but Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel::__construct($label) is not of the type Cbox\Cms\Contracts\Consistency\ProjectionName'],
]);
