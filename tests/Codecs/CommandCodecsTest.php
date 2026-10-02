<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs\Commands;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Fields\BooleanValue;
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
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActivateActorCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\CreateEntryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\CreatePlacementCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DeactivateActorCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PublishEntryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\RegisterActorCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReleaseVariantCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReviseEntryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\SetPlacementWindowCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\UnpublishEntryCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\TypeScript\TypeScriptValidators;
use DateTimeImmutable;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Formats\DateTimeFormats;
use Opis\JsonSchema\Validator;
use ReflectionClass;
use RuntimeException;
use stdClass;

/*
 * The generated codecs of the kernel's commands (GUARDRAILS 2.2, PRD 11.12), held three ways: each
 * command built in PHP gives an equal command after the codec encodes and decodes it; and on the
 * same hand-written documents the PHP codec, the generated TypeScript validator and an independent
 * JSON Schema validator (opis) agree. A document that breaks a rule its JSON Schema states is
 * refused by all three, the PHP codec and the TypeScript validator at the same value; a document
 * that keeps every rule is accepted by all three, and so is everything the PHP codec writes for it.
 * A rule that only the command's class states, such as a window that starts before it ends, is
 * refused by the PHP codec alone, which the fixture says.
 */

const COMMAND_TYPESCRIPT = 'workbench/resources/js/cms/generated';

const COMMAND_ENTRY = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01';

const COMMAND_TYPE = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02';

const COMMAND_NODE = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03';

const COMMAND_PLACEMENT = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a04';

const COMMAND_SITE = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a05';

const COMMAND_ACTOR = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a06';

/** The end of a date-time of RFC 3339: its offset. */
const COMMAND_OFFSET = '/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/i';

/**
 * Whether the command's committed JSON Schema accepts the document, by opis.
 */
function commandSchemaAccepts(string $schema, string $document): bool
{
    $contents = file_get_contents(Phpstan::root().'/'.ProtocolSchemas::COMMAND_SCHEMA_DIRECTORY.'/'.$schema);

    if ($contents === false) {
        throw new RuntimeException('The schema '.$schema.' cannot be read.');
    }

    $validator = new Validator;
    // RFC 3339 requires the offset of a date-time; opis's own format makes it optional.
    $validator->parser()->getFormatResolver()?->registerCallable('string', 'date-time', static fn (string $value): bool => preg_match(COMMAND_OFFSET, $value) === 1 && DateTimeFormats::dateTime($value));

    return ! $validator->validate(json_decode($document, false, 512, JSON_THROW_ON_ERROR), $contents)->error() instanceof ValidationError;
}

/**
 * The path at which the PHP codec refuses a document, '' for the document itself, or null when it
 * accepts it.
 *
 * @template TDto of object
 *
 * @param  JsonCodec<TDto>  $codec
 */
function commandRefusedAt(JsonCodec $codec, string $document): ?string
{
    try {
        $codec->decode($document, ClassificationAccess::Sensitive);
    } catch (DecodingFailed $failure) {
        return $failure->path?->toString() ?? '';
    }

    return null;
}

/**
 * Checks each fixture on the three sides, and what the PHP codec writes for each accepted one on
 * the TypeScript validator and the JSON Schema.
 *
 * @template TDto of object
 *
 * @param  JsonCodec<TDto>  $codec
 * @param  array<string, array{0: string, 1: ?string, 2?: bool}>  $fixtures  name to the document, the path it is refused at or null, and whether the JSON Schema accepts it when that is not the same as whether the path is null
 */
function commandCrossCheck(JsonCodec $codec, string $schema, array $fixtures): void
{
    $name = (string) preg_replace('/Codec(V[0-9]+)\z/', '$1', new ReflectionClass($codec)->getShortName());
    $module = 'protocol/'.$name;
    $validator = 'validate'.$name;
    $cases = [];
    $expected = [];

    foreach ($fixtures as $fixture => $entry) {
        [$document, $path] = $entry;
        $schemaAccepts = $entry[2] ?? $path === null;
        $typeScriptPath = ($entry[2] ?? false) ? null : $path;

        expect(commandRefusedAt($codec, $document))->toBe($path, 'The PHP codec on the fixture '.$fixture)
            ->and(commandSchemaAccepts($schema, $document))->toBe($schemaAccepts, 'The JSON Schema on the fixture '.$fixture);

        $cases[] = ['module' => $module, 'validator' => $validator, 'document' => $document];
        $expected[] = [$fixture, $typeScriptPath];

        if ($path === null) {
            $output = $codec->encode($codec->decode($document, ClassificationAccess::Sensitive), ClassificationAccess::Public);

            expect(commandSchemaAccepts($schema, $output))->toBeTrue('The JSON Schema on what PHP writes for '.$fixture)
                ->and($codec->decode($output, ClassificationAccess::Public))->toEqual($codec->decode($document, ClassificationAccess::Public));

            $cases[] = ['module' => $module, 'validator' => $validator, 'document' => $output];
            $expected[] = [$fixture.', written by PHP', null];
        }
    }

    foreach (TypeScriptValidators::run(COMMAND_TYPESCRIPT, $cases) as $index => $verdict) {
        [$fixture, $path] = $expected[$index];

        expect($verdict['valid'] ? null : ($verdict['path'] ?? ''))->toBe($path, sprintf('The TypeScript validator on %s: %s', $fixture, $verdict['reason'] ?? 'valid'));
    }
}

/**
 * A document of the values, with the keys given replacing or adding to them, and a key given as
 * null left out when $omit names it.
 *
 * @param  array<string, mixed>  $values
 * @param  array<string, mixed>  $changes
 * @param  list<string>  $omit
 */
function commandJson(array $values, array $changes = [], array $omit = []): string
{
    $document = [...$values, ...$changes];

    foreach ($omit as $key) {
        unset($document[$key]);
    }

    return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * The fields of a revision in their JSON form, with every kind of value JSON gives and an
 * extender's fields.
 *
 * @return array<string, mixed>
 */
function commandFields(): array
{
    return [
        'fixture_title' => 'Harbour opens ✓',
        'fixture_featured' => true,
        'fixture_reading_minutes' => 7,
        'fixture_published_on' => '2026-03-09',
        'fixture_summary' => null,
        'fixture_topics' => ['fixture_politics', 'fixture_science'],
        'fixture_embargo' => ['fixture_embargo_until' => '2026-03-10T09:00:00+01:00', 'not a name' => 1, 'nested' => ['deep' => [true, null]]],
        'ext' => ['fixtureaddon' => ['fixture_slug' => 'harbour'], 'empty' => new stdClass],
    ];
}

/**
 * The same fields as the kernel holds them.
 */
function commandFieldValues(): FieldValues
{
    return new FieldValues(
        new FieldMap(
            new NamedValue(new FieldHandle('fixture_title'), new TextValue('Harbour opens ✓')),
            new NamedValue(new FieldHandle('fixture_featured'), new BooleanValue(true)),
            new NamedValue(new FieldHandle('fixture_reading_minutes'), new IntegerValue(7)),
            new NamedValue(new FieldHandle('fixture_published_on'), new TextValue('2026-03-09')),
            new NamedValue(new FieldHandle('fixture_summary'), new NullValue),
            new NamedValue(new FieldHandle('fixture_topics'), new ListValue(new TextValue('fixture_politics'), new TextValue('fixture_science'))),
            new NamedValue(new FieldHandle('fixture_embargo'), new MapValue(
                new MapEntry('fixture_embargo_until', new TextValue('2026-03-10T09:00:00+01:00')),
                new MapEntry('not a name', new IntegerValue(1)),
                new MapEntry('nested', new MapValue(new MapEntry('deep', new ListValue(new BooleanValue(true), new NullValue)))),
            )),
        ),
        new ExtensionFields(new FieldNamespace('fixtureaddon'), new FieldMap(new NamedValue(new FieldHandle('fixture_slug'), new TextValue('harbour')))),
        new ExtensionFields(new FieldNamespace('empty'), new FieldMap),
    );
}

/**
 * The fields of a command's JSON document, decoded to arrays.
 */
function commandFieldsOf(string $json): mixed
{
    $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

    return is_array($document) ? ($document['fields'] ?? null) : null;
}

/**
 * The fixtures of the rules of the fields every command that carries them has, below `fields`.
 *
 * @param  callable(array<array-key, mixed>): string  $with  the command's document with the fields given
 * @return array<string, array{0: string, 1: ?string, 2?: bool}>
 */
function fieldsFixtures(callable $with): array
{
    $fields = commandFields();

    return [
        'no fields' => [$with([]), null],
        'fields that are a list' => [$with(['x']), 'fields'],
        'a handle in upper case' => [$with([...$fields, 'Title' => 'x']), 'fields'],
        'a handle with a double underscore' => [$with([...$fields, 'fixture__title' => 'x']), 'fields'],
        'a handle starting with cms_' => [$with([...$fields, 'cms_id' => 'x']), 'fields'],
        'a handle of 64 characters' => [$with([...$fields, str_repeat('h', 64) => 'x']), 'fields'],
        'a handle of 63 characters' => [$with([...$fields, str_repeat('h', 63) => 'x']), null],
        'a number with a fraction' => [$with([...$fields, 'fixture_score' => 1.5]), 'fields.fixture_score'],
        'a number with a fraction in a list' => [$with([...$fields, 'fixture_scores' => [1, 2.5]]), 'fields.fixture_scores[1]'],
        'an empty key in an object' => [$with([...$fields, 'fixture_map' => ['' => 1]]), 'fields.fixture_map'],
        'extension fields that are a list' => [$with([...$fields, 'ext' => ['x']]), 'fields.ext'],
        'a namespace with an underscore' => [$with([...$fields, 'ext' => ['fixture_addon' => new stdClass]]), 'fields.ext'],
        'the namespace ext' => [$with([...$fields, 'ext' => ['ext' => new stdClass]]), 'fields.ext'],
        'a namespace of 21 characters' => [$with([...$fields, 'ext' => [str_repeat('n', 21) => new stdClass]]), 'fields.ext'],
        'an extender\'s fields that are a string' => [$with([...$fields, 'ext' => ['fixtureaddon' => 'x']]), 'fields.ext.fixtureaddon'],
        'an extender\'s field named ext' => [$with([...$fields, 'ext' => ['fixtureaddon' => ['ext' => 'x']]]), 'fields.ext.fixtureaddon'],
        'an extender\'s value with a fraction' => [$with([...$fields, 'ext' => ['fixtureaddon' => ['fixture_rate' => 0.5]]]), 'fields.ext.fixtureaddon.fixture_rate'],
    ];
}

/**
 * The fixtures of an id every command has: another UUID version, a number, and null.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, array{0: string, 1: ?string}>
 */
function idFixtures(array $document, string $key): array
{
    return [
        $key.' of another UUID version' => [commandJson($document, [$key => '0199a3c1-2b4d-4e5f-8a6b-1c2d3e4f5a01']), $key],
        $key.' as a number' => [commandJson($document, [$key => 1]), $key],
        $key.' null' => [commandJson($document, [$key => null]), $key],
        $key.' missing' => [commandJson($document, omit: [$key]), $key],
    ];
}

/**
 * The fixtures of a version every command that expects one has.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, array{0: string, 1: ?string}>
 */
function versionFixtures(array $document, string $key): array
{
    return [
        $key.' of zero' => [commandJson($document, [$key => 0]), $key],
        $key.' in a string' => [commandJson($document, [$key => '1']), $key],
        $key.' with a fraction' => [commandJson($document, [$key => 1.5]), $key],
        $key.' null' => [commandJson($document, [$key => null]), $key],
        $key.' missing' => [commandJson($document, omit: [$key]), $key],
    ];
}

/**
 * The fixtures of a locale.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, array{0: string, 1: ?string}>
 */
function localeFixtures(array $document, string $key, string $path): array
{
    return [
        $key.' with a region in lower case' => [commandJson($document, [$key => 'en-gb']), null],
        $key.' with a script' => [commandJson($document, [$key => 'sr-Latn-RS']), null],
        $key.' of one letter' => [commandJson($document, [$key => 'd']), $path],
        $key.' with an underscore' => [commandJson($document, [$key => 'en_GB']), $path],
    ];
}

/**
 * The fixtures of a window.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, array{0: string, 1: ?string, 2?: bool}>
 */
function windowFixtures(array $document): array
{
    return [
        'a window from an instant' => [commandJson($document, ['window' => ['live_from' => '2026-03-10T09:00:00+01:00']]), null],
        'a window of two instants with six decimals' => [commandJson($document, ['window' => ['live_from' => '2026-03-10T09:00:00.000001Z', 'live_until' => '2026-03-11T09:00:00.5Z']]), null],
        'an open window' => [commandJson($document, ['window' => new stdClass]), null],
        'a window with nulls' => [commandJson($document, ['window' => ['live_from' => null, 'live_until' => null]]), null],
        'a window with an unknown key' => [commandJson($document, ['window' => ['live_at' => '2026-03-10T09:00:00Z']]), 'window'],
        'a window from a day' => [commandJson($document, ['window' => ['live_from' => '2026-03-10']]), 'window.live_from'],
        'a window until a time without an offset' => [commandJson($document, ['window' => ['live_until' => '2026-03-10T09:00:00']]), 'window.live_until'],
        'a window that is a list' => [commandJson($document, ['window' => []]), 'window'],
        // TimeWindow states that the start is before the end; the JSON Schema cannot.
        'a window that ends before it starts' => [commandJson($document, ['window' => ['live_from' => '2026-03-11T09:00:00Z', 'live_until' => '2026-03-10T09:00:00Z']]), 'window', true],
    ];
}

it('decodes every command it encodes into an equal command', function (JsonCodec $codec, Command $command): void {
    $json = $codec->encode($command, ClassificationAccess::Public);

    expect($codec->decode($json, ClassificationAccess::Public))->toEqual($command)
        ->and($codec->encode($codec->decode($json, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($json);
})->with(static fn (): array => [
    'entry.create' => [new CreateEntryCodecV1, new CreateEntry(EntryId::fromString(COMMAND_ENTRY), TypeId::fromString(COMMAND_TYPE), NodeId::fromString(COMMAND_NODE), commandFieldValues())],
    'entry.create without fields' => [new CreateEntryCodecV1, new CreateEntry(EntryId::fromString(COMMAND_ENTRY), TypeId::fromString(COMMAND_TYPE), NodeId::fromString(COMMAND_NODE), new FieldValues)],
    'entry.revise' => [new ReviseEntryCodecV1, new ReviseEntry(EntryId::fromString(COMMAND_ENTRY), new AggregateVersion(4), commandFieldValues())],
    'variant.release' => [new ReleaseVariantCodecV1, new ReleaseVariant(EntryId::fromString(COMMAND_ENTRY), new RevisionNumber(3), new AggregateVersion(5))],
    'entry.publish now' => [new PublishEntryCodecV1, new PublishEntry(EntryId::fromString(COMMAND_ENTRY), new AggregateVersion(2), new RevisionNumber(1), PlacementId::fromString(COMMAND_PLACEMENT), new AggregateVersion(1), new Locale('da'))],
    'entry.publish in a window without a revision' => [new PublishEntryCodecV1, new PublishEntry(EntryId::fromString(COMMAND_ENTRY), new AggregateVersion(2), null, PlacementId::fromString(COMMAND_PLACEMENT), new AggregateVersion(1), new Locale('en-GB'), new TimeWindow(new DateTimeImmutable('2026-03-10T09:00:00.25+01:00'), new DateTimeImmutable('2026-04-01T00:00:00Z')))],
    'entry.unpublish' => [new UnpublishEntryCodecV1, new UnpublishEntry(EntryId::fromString(COMMAND_ENTRY), new AggregateVersion(7))],
    'placement.create' => [new CreatePlacementCodecV1, new CreatePlacement(PlacementId::fromString(COMMAND_PLACEMENT), EntryId::fromString(COMMAND_ENTRY), NodeId::fromString(COMMAND_NODE), SiteId::fromString(COMMAND_SITE), [new LocaleSlug(new Locale('da'), new Slug('havnen')), new LocaleSlug(new Locale('en'), new Slug('the-harbour'))])],
    'placement.set_window' => [new SetPlacementWindowCodecV1, new SetPlacementWindow(PlacementId::fromString(COMMAND_PLACEMENT), new AggregateVersion(3), new Locale('da'), new TimeWindow(until: new DateTimeImmutable('2026-12-31T23:00:00Z')))],
    'placement.set_window hidden' => [new SetPlacementWindowCodecV1, new SetPlacementWindow(PlacementId::fromString(COMMAND_PLACEMENT), new AggregateVersion(3), new Locale('da'), null)],
    'actor.deactivate' => [new DeactivateActorCodecV1, new DeactivateActor(ActorId::fromString(COMMAND_ACTOR))],
    'actor.deactivate for inactivity' => [new DeactivateActorCodecV1, new DeactivateActor(ActorId::fromString(COMMAND_ACTOR), DeactivationSource::Inactivity)],
    'actor.register of staff' => [new RegisterActorCodecV1, new RegisterActor(ActorId::fromString(COMMAND_ACTOR), ActorClass::Staff, new DisplayName('Mette Holm ✓'), new EmailAddress('mette@example.com'))],
    'actor.register of a service' => [new RegisterActorCodecV1, new RegisterActor(ActorId::fromString(COMMAND_ACTOR), ActorClass::Service, new DisplayName('Nightly import'), new EmailAddress('ops+import@example.co.uk'), ActorId::fromString(COMMAND_ENTRY))],
    'actor.activate' => [new ActivateActorCodecV1, new ActivateActor(ActorId::fromString(COMMAND_ACTOR), new AggregateVersion(1))],
]);

it('reads the fields of entry.create as JSON gives them, and writes them back in the same form', function (): void {
    $document = commandJson(['entry' => COMMAND_ENTRY, 'fields' => commandFields(), 'home' => COMMAND_NODE, 'type' => COMMAND_TYPE]);
    $command = new CreateEntryCodecV1()->decode($document, ClassificationAccess::Public);

    expect($command->fields->equals(commandFieldValues()))->toBeTrue()
        ->and(commandFieldsOf(new CreateEntryCodecV1()->encode($command, ClassificationAccess::Public)))->toBe([
            'ext' => ['empty' => [], 'fixtureaddon' => ['fixture_slug' => 'harbour']],
            'fixture_embargo' => ['fixture_embargo_until' => '2026-03-10T09:00:00+01:00', 'nested' => ['deep' => [true, null]], 'not a name' => 1],
            'fixture_featured' => true,
            'fixture_published_on' => '2026-03-09',
            'fixture_reading_minutes' => 7,
            'fixture_summary' => null,
            'fixture_title' => 'Harbour opens ✓',
            'fixture_topics' => ['fixture_politics', 'fixture_science'],
        ]);
});

it('refuses every rule of entry.create and entry.revise that their JSON Schemas state, as the TypeScript validators and the schemas do', function (): void {
    $create = ['entry' => COMMAND_ENTRY, 'fields' => commandFields(), 'home' => COMMAND_NODE, 'type' => COMMAND_TYPE];
    $revise = ['entry' => COMMAND_ENTRY, 'fields' => commandFields(), 'version' => 3];

    commandCrossCheck(new CreateEntryCodecV1, 'entry.create.v1.json', [
        'a create' => [commandJson($create), null],
        'an id in upper case' => [commandJson($create, ['entry' => strtoupper(COMMAND_ENTRY)]), null],
        'an unknown key' => [commandJson($create, ['revision' => 1]), ''],
        'a document that is a list' => ['[]', ''],
        ...idFixtures($create, 'entry'),
        ...idFixtures($create, 'type'),
        ...idFixtures($create, 'home'),
        'fields missing' => [commandJson($create, omit: ['fields']), 'fields'],
        'fields null' => [commandJson($create, ['fields' => null]), 'fields'],
        ...fieldsFixtures(static fn (array $fields): string => commandJson($create, ['fields' => $fields === [] ? new stdClass : $fields])),
    ]);

    commandCrossCheck(new ReviseEntryCodecV1, 'entry.revise.v1.json', [
        'a revise' => [commandJson($revise), null],
        'an unknown key' => [commandJson($revise, ['type' => COMMAND_TYPE]), ''],
        ...idFixtures($revise, 'entry'),
        ...versionFixtures($revise, 'version'),
        ...fieldsFixtures(static fn (array $fields): string => commandJson($revise, ['fields' => $fields === [] ? new stdClass : $fields])),
    ]);
});

it('refuses every rule of variant.release, entry.publish and entry.unpublish that their JSON Schemas state, as the TypeScript validators and the schemas do', function (): void {
    $release = ['entry' => COMMAND_ENTRY, 'revision' => 2, 'version' => 3];
    $publish = ['entry' => COMMAND_ENTRY, 'locale' => 'da', 'placement' => COMMAND_PLACEMENT, 'placement_version' => 1, 'revision' => 1, 'version' => 2];
    $unpublish = ['entry' => COMMAND_ENTRY, 'version' => 4];

    commandCrossCheck(new ReleaseVariantCodecV1, 'variant.release.v1.json', [
        'a release' => [commandJson($release), null],
        'an unknown key' => [commandJson($release, ['locale' => 'da']), ''],
        ...idFixtures($release, 'entry'),
        ...versionFixtures($release, 'version'),
        ...versionFixtures($release, 'revision'),
    ]);

    commandCrossCheck(new PublishEntryCodecV1, 'entry.publish.v1.json', [
        'a publish now' => [commandJson($publish), null],
        'a publish without a revision' => [commandJson($publish, ['revision' => null]), null],
        'a publish with a null window' => [commandJson($publish, ['window' => null]), null],
        'an unknown key' => [commandJson($publish, ['site' => COMMAND_SITE]), ''],
        'the revision missing' => [commandJson($publish, omit: ['revision']), 'revision'],
        'a revision of zero' => [commandJson($publish, ['revision' => 0]), 'revision'],
        'a revision in a string' => [commandJson($publish, ['revision' => '1']), 'revision'],
        ...idFixtures($publish, 'entry'),
        ...idFixtures($publish, 'placement'),
        ...versionFixtures($publish, 'version'),
        ...versionFixtures($publish, 'placement_version'),
        ...localeFixtures($publish, 'locale', 'locale'),
        'the locale missing' => [commandJson($publish, omit: ['locale']), 'locale'],
        ...windowFixtures($publish),
    ]);

    commandCrossCheck(new UnpublishEntryCodecV1, 'entry.unpublish.v1.json', [
        'an unpublish' => [commandJson($unpublish), null],
        'an unknown key' => [commandJson($unpublish, ['locale' => 'da']), ''],
        ...idFixtures($unpublish, 'entry'),
        ...versionFixtures($unpublish, 'version'),
    ]);
});

it('refuses every rule of placement.create, placement.set_window and actor.deactivate that their JSON Schemas state, as the TypeScript validators and the schemas do', function (): void {
    $slug = static fn (string $locale, string $slug): array => ['locale' => $locale, 'slug' => $slug];
    $place = ['entry' => COMMAND_ENTRY, 'node' => COMMAND_NODE, 'placement' => COMMAND_PLACEMENT, 'site' => COMMAND_SITE, 'slugs' => [$slug('da', 'havnen'), $slug('en', 'the-harbour')]];
    $window = ['locale' => 'da', 'placement' => COMMAND_PLACEMENT, 'version' => 2, 'window' => ['live_from' => '2026-03-10T09:00:00Z']];
    $deactivate = ['actor' => COMMAND_ACTOR, 'source' => 'local'];

    commandCrossCheck(new CreatePlacementCodecV1, 'placement.create.v1.json', [
        'a placement' => [commandJson($place), null],
        'a placement without slugs, which the action refuses' => [commandJson($place, ['slugs' => []]), null],
        'a slug of 255 characters of four bytes each' => [commandJson($place, ['slugs' => [$slug('da', str_repeat('😀', 255))]]), null],
        'an unknown key' => [commandJson($place, ['home' => COMMAND_NODE]), ''],
        ...idFixtures($place, 'placement'),
        ...idFixtures($place, 'entry'),
        ...idFixtures($place, 'node'),
        ...idFixtures($place, 'site'),
        'slugs missing' => [commandJson($place, omit: ['slugs']), 'slugs'],
        'slugs that are an object' => [commandJson($place, ['slugs' => $slug('da', 'havnen')]), 'slugs'],
        'a slug that is a string' => [commandJson($place, ['slugs' => ['havnen']]), 'slugs[0]'],
        'a slug with an unknown key' => [commandJson($place, ['slugs' => [[...$slug('da', 'havnen'), 'canonical' => true]]]), 'slugs[0]'],
        'a slug without its locale' => [commandJson($place, ['slugs' => [['slug' => 'havnen']]]), 'slugs[0].locale'],
        'a slug in a locale of one letter' => [commandJson($place, ['slugs' => [$slug('d', 'havnen')]]), 'slugs[0].locale'],
        'an empty slug' => [commandJson($place, ['slugs' => [$slug('da', '')]]), 'slugs[0].slug'],
        'a slug with a slash' => [commandJson($place, ['slugs' => [$slug('da', 'havnen/nord')]]), 'slugs[0].slug'],
        'a slug with a space' => [commandJson($place, ['slugs' => [$slug('da', 'the harbour')]]), 'slugs[0].slug'],
        'the slug ..' => [commandJson($place, ['slugs' => [$slug('da', '..')]]), 'slugs[0].slug'],
        'the slug .' => [commandJson($place, ['slugs' => [$slug('da', '.')]]), 'slugs[0].slug'],
        'the slug ...' => [commandJson($place, ['slugs' => [$slug('da', '...')]]), null],
        'a slug of 256 characters' => [commandJson($place, ['slugs' => [$slug('da', str_repeat('s', 256))]]), 'slugs[0].slug'],
    ]);

    commandCrossCheck(new SetPlacementWindowCodecV1, 'placement.set_window.v1.json', [
        'a window' => [commandJson($window), null],
        'a hidden placement' => [commandJson($window, ['window' => null]), null],
        'the window missing' => [commandJson($window, omit: ['window']), 'window'],
        'an unknown key' => [commandJson($window, ['entry' => COMMAND_ENTRY]), ''],
        ...idFixtures($window, 'placement'),
        ...versionFixtures($window, 'version'),
        ...localeFixtures($window, 'locale', 'locale'),
        ...windowFixtures($window),
    ]);

    commandCrossCheck(new DeactivateActorCodecV1, 'actor.deactivate.v1.json', [
        'a deactivation' => [commandJson($deactivate), null],
        'a deactivation for inactivity' => [commandJson($deactivate, ['source' => 'inactivity']), null],
        'a deactivation without its source' => [commandJson($deactivate, omit: ['source']), null],
        'an unknown key' => [commandJson($deactivate, ['reason' => 'left']), ''],
        'a source that is not one' => [commandJson($deactivate, ['source' => 'scim']), 'source'],
        'a source of null' => [commandJson($deactivate, ['source' => null]), 'source'],
        ...idFixtures($deactivate, 'actor'),
    ]);
});

it('refuses every rule of actor.register and actor.activate that their JSON Schemas state, as the TypeScript validators and the schemas do', function (): void {
    $register = ['actor' => COMMAND_ACTOR, 'class' => 'staff', 'display_name' => 'Mette Holm', 'email' => 'mette@example.com'];
    $activate = ['actor' => COMMAND_ACTOR, 'version' => 1];

    commandCrossCheck(new RegisterActorCodecV1, 'actor.register.v1.json', [
        'a staff registration' => [commandJson($register), null],
        'a service registration with its responsible person' => [commandJson($register, ['class' => 'service', 'responsible' => COMMAND_ENTRY]), null],
        'an end user, which the action refuses' => [commandJson($register, ['class' => 'end_user']), null],
        'a responsible person of null' => [commandJson($register, ['responsible' => null]), null],
        'a display name of 200 characters of four bytes each' => [commandJson($register, ['display_name' => str_repeat('😀', 200)]), null],
        'a display name with inner spaces and letters of any script' => [commandJson($register, ['display_name' => 'Søren Ærø-Ågård']), null],
        'an unknown key' => [commandJson($register, ['state' => 'active']), ''],
        ...idFixtures($register, 'actor'),
        'a class that is not one' => [commandJson($register, ['class' => 'agent']), 'class'],
        'the class missing' => [commandJson($register, omit: ['class']), 'class'],
        'the display name missing' => [commandJson($register, omit: ['display_name']), 'display_name'],
        'an empty display name' => [commandJson($register, ['display_name' => '']), 'display_name'],
        'a display name that starts with a space' => [commandJson($register, ['display_name' => ' Mette']), 'display_name'],
        'a display name that ends with a space' => [commandJson($register, ['display_name' => 'Mette ']), 'display_name'],
        'a display name with a line break' => [commandJson($register, ['display_name' => "Mette\nHolm"]), 'display_name'],
        'a display name of 201 characters' => [commandJson($register, ['display_name' => str_repeat('m', 201)]), 'display_name'],
        'a display name that is a number' => [commandJson($register, ['display_name' => 7]), 'display_name'],
        'the email missing' => [commandJson($register, omit: ['email']), 'email'],
        'an email without an @' => [commandJson($register, ['email' => 'mette.example.com']), 'email'],
        'an email without a dot in its domain' => [commandJson($register, ['email' => 'mette@localhost']), 'email'],
        'an email with a space' => [commandJson($register, ['email' => 'mette holm@example.com']), 'email'],
        'an email with two @' => [commandJson($register, ['email' => 'mette@holm@example.com']), 'email'],
        'an email of 255 characters' => [commandJson($register, ['email' => str_repeat('m', 243).'@example.com']), 'email'],
        'a responsible person that is not an id' => [commandJson($register, ['responsible' => 'someone']), 'responsible'],
    ]);

    commandCrossCheck(new ActivateActorCodecV1, 'actor.activate.v1.json', [
        'an activation' => [commandJson($activate), null],
        'an unknown key' => [commandJson($activate, ['source' => 'local']), ''],
        ...idFixtures($activate, 'actor'),
        ...versionFixtures($activate, 'version'),
    ]);
});
