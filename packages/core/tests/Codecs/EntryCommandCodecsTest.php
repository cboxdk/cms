<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Boundary\CreateEntryCodec;
use Cbox\Cms\Core\Entries\Boundary\ReviseEntryCodec;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Pipeline\Boundary\FieldValuesInput;
use DateTimeImmutable;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/*
 * The codecs of entry.create and entry.revise, version 1 (GUARDRAILS 2.2, PRD 5.4): every exposed
 * surface reads the commands with them and cms:build describes their REST routes with their JSON
 * Schemas. A command round-trips through its canonical JSON, which validates against the codec's
 * own schema with an independent validator; the fields are the input form the type's validator
 * reads, so writing them back gives the caller's document; and the codecs refuse what the schemas
 * refuse.
 */

const CODEC_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000005e1';
const CODEC_NODE = '01936f5e-8a2b-7c3d-9e4f-0000000005a1';
const CODEC_TYPE = '01936f5e-8a2b-7c3d-9e4f-0000000005d1';

const CREATE_ENTRY_JSON = '{"entry":"01936f5e-8a2b-7c3d-9e4f-0000000005e1","fields":{"body":[{"_key":"a1","_type":"block","children":[{"_type":"span","text":"Hi"}]}],"count":3,"ext":{"shop":{"price":"12.50"}},"flag":true,"note":null,"tags":["a","b"],"title":"Hello"},"home":"01936f5e-8a2b-7c3d-9e4f-0000000005a1","type":"01936f5e-8a2b-7c3d-9e4f-0000000005d1"}';

const REVISE_ENTRY_JSON = '{"entry":"01936f5e-8a2b-7c3d-9e4f-0000000005e1","fields":{"title":"Hello again"},"version":4}';

/**
 * The validation errors of $json against the JSON Schema $schema, by JSON pointer.
 *
 * @return array<string, list<string>>
 */
function entryCommandSchemaErrors(string $schema, string $json): array
{
    $error = new Validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $schema)->error();

    if (! $error instanceof ValidationError) {
        return [];
    }

    /** @var array<string, list<string>> $errors */
    $errors = new ErrorFormatter()->format($error, true);

    return $errors;
}

function createEntryCommand(): CreateEntry
{
    return new CreateEntry(
        EntryId::fromString(CODEC_ENTRY),
        TypeId::fromString(CODEC_TYPE),
        NodeId::fromString(CODEC_NODE),
        new FieldValues(
            new FieldMap(
                new NamedValue(new FieldHandle('title'), new TextValue('Hello')),
                new NamedValue(new FieldHandle('count'), new IntegerValue(3)),
                new NamedValue(new FieldHandle('flag'), new BooleanValue(true)),
                new NamedValue(new FieldHandle('note'), new NullValue),
                new NamedValue(new FieldHandle('tags'), new ListValue(new TextValue('a'), new TextValue('b'))),
                new NamedValue(new FieldHandle('body'), new ListValue(new MapValue(
                    new MapEntry('_type', new TextValue('block')),
                    new MapEntry('_key', new TextValue('a1')),
                    new MapEntry('children', new ListValue(new MapValue(new MapEntry('_type', new TextValue('span')), new MapEntry('text', new TextValue('Hi'))))),
                ))),
            ),
            new ExtensionFields(new FieldNamespace('shop'), new FieldMap(new NamedValue(new FieldHandle('price'), new TextValue('12.50')))),
        ),
    );
}

it('writes entry.create as canonical JSON that validates against its schema, and reads it back equal', function (): void {
    $codec = new CreateEntryCodec;
    $json = $codec->encode(createEntryCommand(), ClassificationAccess::Public);
    $decoded = $codec->decode($json, ClassificationAccess::Public);

    expect($json)->toBe(CREATE_ENTRY_JSON)
        ->and(entryCommandSchemaErrors(CreateEntryCodec::SCHEMA, $json))->toBe([])
        ->and($decoded)->toEqual(createEntryCommand())
        ->and($codec->encode($decoded, ClassificationAccess::Public))->toBe($json);
});

it('writes entry.revise as canonical JSON that validates against its schema, and reads it back equal', function (): void {
    $codec = new ReviseEntryCodec;
    $command = new ReviseEntry(EntryId::fromString(CODEC_ENTRY), new AggregateVersion(4), new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue('Hello again')))));
    $json = $codec->encode($command, ClassificationAccess::Public);
    $decoded = $codec->decode($json, ClassificationAccess::Public);

    expect($json)->toBe(REVISE_ENTRY_JSON)
        ->and(entryCommandSchemaErrors(ReviseEntryCodec::SCHEMA, $json))->toBe([])
        ->and($decoded)->toEqual($command)
        ->and($decoded->expectedVersions())->toEqual($command->expectedVersions());
});

it('reads the fields in the input form the type validator checks, and gives that same form back', function (): void {
    $fields = new FieldValues(new FieldMap(
        new NamedValue(new FieldHandle('price'), new DecimalValue('12.50')),
        new NamedValue(new FieldHandle('starts'), new DateTimeValue(new DateTimeImmutable('2026-01-01T12:00:00.000000Z'))),
    ));
    $command = new ReviseEntry(EntryId::fromString(CODEC_ENTRY), new AggregateVersion(1), $fields);
    $codec = new ReviseEntryCodec;
    $decoded = $codec->decode($codec->encode($command, ClassificationAccess::Public), ClassificationAccess::Public);

    expect(FieldValuesInput::of($decoded->fields))->toEqual(FieldValuesInput::of($fields))
        ->and($decoded->fields->own->get(new FieldHandle('price')))->toEqual(new TextValue(new DecimalValue('12.50')->value));
});

it('refuses a document that breaks the schema of entry.create, as the schema does', function (string $json, ?string $path, string $reason): void {
    expect(Failures::described(static fn (): CreateEntry => new CreateEntryCodec()->decode($json, ClassificationAccess::Public)))->toBe(['json_invalid', $path, $reason])
        ->and(entryCommandSchemaErrors(CreateEntryCodec::SCHEMA, $json))->not->toBe([]);
})->with([
    'no fields' => ['{"entry":"'.CODEC_ENTRY.'","home":"'.CODEC_NODE.'","type":"'.CODEC_TYPE.'"}', 'fields', 'is missing, and the field is required'],
    'an unknown key' => ['{"entry":"'.CODEC_ENTRY.'","fields":{},"home":"'.CODEC_NODE.'","type":"'.CODEC_TYPE.'","owner":"x"}', null, 'has the key "owner", which is not a field of the contract'],
    'fields that are a list' => ['{"entry":"'.CODEC_ENTRY.'","fields":[],"home":"'.CODEC_NODE.'","type":"'.CODEC_TYPE.'"}', 'fields', 'is not an object of fields by handle'],
    'a reserved handle' => ['{"entry":"'.CODEC_ENTRY.'","fields":{"cms_title":"A"},"home":"'.CODEC_NODE.'","type":"'.CODEC_TYPE.'"}', 'fields', 'has the key "cms_title", which is not a field handle: A field handle is lowercase snake_case of at most 63 bytes without a double underscore, and neither "ext" nor starting with "cms_", got "cms_title".'],
    'a handle in capitals' => ['{"entry":"'.CODEC_ENTRY.'","fields":{"Title":"A"},"home":"'.CODEC_NODE.'","type":"'.CODEC_TYPE.'"}', 'fields', 'has the key "Title", which is not a field handle: A field handle is lowercase snake_case of at most 63 bytes without a double underscore, and neither "ext" nor starting with "cms_", got "Title".'],
    'a bad namespace' => ['{"entry":"'.CODEC_ENTRY.'","fields":{"ext":{"Shop":{}}},"home":"'.CODEC_NODE.'","type":"'.CODEC_TYPE.'"}', 'fields.ext', 'has the key "Shop", which is not a namespace: A field namespace is a lowercase letter followed by at most 19 lowercase letters and digits, and not "ext", got "Shop".'],
    'a number with a fraction' => ['{"entry":"'.CODEC_ENTRY.'","fields":{"price":1.5},"home":"'.CODEC_NODE.'","type":"'.CODEC_TYPE.'"}', 'fields.price', 'holds a number that is not an integer'],
    'an entry id that is not a UUIDv7' => ['{"entry":"not-an-id","fields":{},"home":"'.CODEC_NODE.'","type":"'.CODEC_TYPE.'"}', 'entry', 'is not a valid id: Expected a UUID in the form xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx with hex digits, got "not-an-id".'],
]);

it('refuses a document that breaks the schema of entry.revise, as the schema does', function (string $json, ?string $path, string $reason): void {
    expect(Failures::described(static fn (): ReviseEntry => new ReviseEntryCodec()->decode($json, ClassificationAccess::Public)))->toBe(['json_invalid', $path, $reason])
        ->and(entryCommandSchemaErrors(ReviseEntryCodec::SCHEMA, $json))->not->toBe([]);
})->with([
    'no version' => ['{"entry":"'.CODEC_ENTRY.'","fields":{}}', 'version', 'is missing, and the field is required'],
    'version zero' => ['{"entry":"'.CODEC_ENTRY.'","fields":{},"version":0}', 'version', 'is 0, less than the minimum 1'],
    'a version in a string' => ['{"entry":"'.CODEC_ENTRY.'","fields":{},"version":"1"}', 'version', 'is not an integer'],
    'extension fields that are not an object' => ['{"entry":"'.CODEC_ENTRY.'","fields":{"ext":{"shop":[]}},"version":1}', 'fields.ext.shop', 'is not an object of fields by handle'],
]);
