---
title: Add a content type
weight: 41
description: "Add a content type as one blueprint file: the schema line, cms:generate with its code, TypeScript and migration, check:generated, and the tests that list the workbench's types."
---

# Add a content type

The kernel knows no content types (GUARDRAILS 2.4). A type is one blueprint file, and everything else about it is generated from that file. Adding a type therefore changes the schema, the generated code, and the few tests that list the workbench's types by name, and nothing below `packages/`. The workbench's third type, `app:fixture_event`, was added this way (M1-T50).

## Inputs

- **owner**: the owner of the schema root, `app` for the workbench's `workbench/schema`.
- **handle**: lowercase words joined by underscores. In the workbench every handle, field handle and select value starts with `fixture_`, because `tests/Arch/ContentTypesTest.php` fails on one that does not, and fails when a handle appears in the kernel's source.
- **type_id**: a new UUIDv7 in lowercase, unique across every blueprint. `php -r 'require "vendor/autoload.php"; echo Ramsey\Uuid\Uuid::uuid7(), PHP_EOL;'` prints one.
- **capabilities**: `history` (`full`, `audit-only` or `none`), `stages` (`draft-release` or `none`), `localization` (`none` in blueprint v1) and `routable`. An entry of a type with stages `none` has no release and is public once it is placed and published. A type with stages `draft-release` is released with `variant.release`, which the kernel allows only with history `full` and otherwise rejects as `type_not_releasable`.
- **fields**: each with its handle, core field type, `required`, `classification` and the options of its type, as [blueprint schema v1](../addons/blueprint-v1.md) lists them.

From the owner and handle come the names every generator uses: the type's name `<owner>:<handle>`, its `TypeHandle` case `<Case>` (the owner and handle in TitleCase, `AppFixtureEvent`), and its table `<owner>__<handle>`.

## Files

| Path | What it holds | Written by |
|---|---|---|
| `workbench/schema/<handle>.yaml` | the blueprint | hand |
| `workbench/app/Cms/Generated/Boundary/<Case>CodecV1.php` and `Domain/Dto/<Case>V1*.php` | the record DTOs and JSON codec | generated |
| `workbench/app/Cms/Generated/Records/<Case>/` | the record interfaces, the composite record, its factory, a `*Choice` enum per select and a class per group | generated |
| `workbench/app/Cms/Generated/QueryBuilders/<Case>/` | `<Case>Query` with its filter and sort field enums | generated |
| `workbench/app/Cms/Generated/Validators/<Case>Validator.php` | the runtime validator | generated |
| `workbench/app/Cms/Generated/TypeHandle.php`, `GeneratedTypeCatalog.php`, `GeneratedTypeValidators.php`, `GeneratedRecordCodecs.php`, `GeneratedTypesServiceProvider.php` | the type added to the catalog, validators, codecs and bindings | generated |
| `workbench/resources/js/cms/generated/index.ts` and `records/<Case>V1.ts` | the TypeScript types and validator | generated |
| `workbench/database/migrations/cms/<owner>__<handle>_0001_create.php` and `<owner>__<handle>.lock` | the type table's migration and its schema lock | generated |
| `tests/Feature/Workbench/WorkbenchSchemaTest.php` | the exact list of the workbench's blueprint files and generated files, and what the new type's blueprint reads as | hand |
| `tests/Postgres/WalkingSkeleton/RlsWithoutActorContextTest.php` | a row of the new type table in `TYPE_TABLE_ROWS`, with a value for each NOT NULL column | hand |
| `tests/Postgres/WalkingSkeleton/<Name>Test.php` | an end-to-end test, when the type has a combination of capabilities no other type has | hand |

## Steps

1. Write the blueprint in `workbench/schema/<handle>.yaml`: `blueprint: 1`, `kind: type`, the `type_id`, `handle`, `label`, `description`, `version: 1`, the capabilities and the fields.
2. Run `vendor/bin/testbench cms:schema:editor`. It gives the file its first line, `# yaml-language-server: $schema=../../packages/contracts/resources/schemas/blueprint.v1.json`, so an editor validates the file as you write it.
3. Run `vendor/bin/testbench cms:generate`. It validates every blueprint, stops with a code such as `generate_duplicate_type_id` or `generate_schema_invalid` and the path of what is wrong, and otherwise writes the generated files above, the migration and the schema lock included. Never edit a generated file; change the blueprint and run the command again.
4. Update the two tests that list the workbench's types, then stage everything with `git add`. The migration runs in the tests on its own, because the Postgres harness runs `migrate:fresh`; for the shared dev database, `composer dev:prepare` runs it from the main checkout.
5. Run `composer check:generated`. It fails on generated files that are not staged or that differ from what `cms:generate` and `composer generate:protocol` write now.
6. Run `composer check`, and record the two changed tests, and any new one, in `CHECKS-LOG.md`.

A later change to the type is a new `version` of the same file. Until schema evolution comes (B3), a type table only grows: new optional fields give a `<owner>__<handle>_<step>_add_columns` migration, and `cms:generate` refuses every other change to a type that has a schema lock (`generate_field_changed`, `generate_field_removed`, `generate_table_changed`, `generate_type_removed`).

## Checks

- `tests/Feature/Workbench/WorkbenchSchemaTest.php` fails until it lists the new blueprint file and every file generated from it. That is on purpose: the list is the one place the workbench's types are named.
- `tests/Postgres/WalkingSkeleton/RlsWithoutActorContextTest.php` fails for a type table it has no row for, because it checks that every table under row level security, generated type tables included, holds no row for the app role without an actor context.
- `tests/Arch/ContentTypesTest.php` fails for a handle without the `fixture_` prefix, or when the kernel's source names one.
- A test below `packages/` that fails because a type was added reads the workbench by name. That test is the bug: fix it to read the types from the schema or the `TypeCatalog`, as M1-T50 did, in a commit of its own, and record it in `CHECKS-LOG.md`.
- `composer check:generated` (gate 6) and `composer check` (gates 1 to 6) pass.

## Running example

The workbench's third type, `app:fixture_event`, is history `audit-only`, stages `none` and routable. Its blueprint is the only file of M1-T50 that is not generated or a test:

<!-- example-file: workbench/schema/fixture_event.yaml -->
```yaml
# yaml-language-server: $schema=../../packages/contracts/resources/schemas/blueprint.v1.json
# The workbench's third fixture type (GUARDRAILS 2.4, MILESTONES M1 exit criteria), owner app:
# added as this schema file alone, with no change in the modules' code (packages/*/src), and working through the
# generators, the commands, the delivery API and every surface. Its capabilities differ from both
# other types: history audit-only (the kernel keeps head snapshots and no revisions, as
# fixture_measurement's none does, while fixture_article keeps every revision), stages none and
# routable, so an entry of it is public once it is placed and published, without a release. Its
# fields use what the other two do not: a unit on an integer and a decimal, a top-level e-mail
# address hidden from agents, a long text with a lower bound, rich text with links and numbered
# lists, a select that is sortable, and a group with a group inside it. Every handle and select
# value starts with fixture_, as in fixture_article.yaml. tests/Postgres/WalkingSkeleton
# /ThirdTypeTest.php runs it end to end. cms:generate reads it and writes the committed code in
# workbench/app/Cms/Generated, workbench/resources/js/cms/generated and the migration in
# workbench/database/migrations/cms; the first line is written by cms:schema:editor.
blueprint: 1
kind: type
type_id: 01a0f424-cbfd-727a-9467-bff093dc17b2
handle: fixture_event
label: Fixture event
description: A public event with a venue, a programme, a price and the seats it has.
version: 1
capabilities:
  history: audit-only
  stages: none
  localization: none
  routable: true
fields:
  - handle: fixture_name
    label: Name
    description: The name of the event, as it is shown on the page and in lists.
    type: text
    required: true
    classification: public
    sortable: true
    min_length: 2
    max_length: 120
  - handle: fixture_starts_at
    label: Starts at
    description: When the event starts.
    type: datetime
    required: true
    classification: public
    filterable: true
    sortable: true
  - handle: fixture_kind
    label: Kind
    description: What kind of event it is.
    type: select
    required: true
    classification: public
    filterable: true
    sortable: true
    options:
      - value: fixture_concert
        label: Concert
      - value: fixture_lecture
        label: Lecture
      - value: fixture_market
        label: Market
  - handle: fixture_seats
    label: Seats
    description: How many seats the venue has for the event.
    type: integer
    classification: public
    filterable: true
    min: 0
    max: 100000
    unit: seats
  - handle: fixture_price
    label: Price
    description: The price of a ticket.
    type: decimal
    classification: public
    sortable: true
    precision: 8
    scale: 2
    min: '0.00'
    unit: DKK
  - handle: fixture_summary
    label: Summary
    description: A short summary of the event for lists.
    type: long_text
    classification: public
    min_length: 10
    max_length: 500
  - handle: fixture_programme
    label: Programme
    description: The programme of the event, with links to the performers.
    type: rich_text
    classification: public
    styles: [normal, h3]
    marks: [em]
    lists: [number]
    links: [url]
  - handle: fixture_free
    label: Free
    description: Whether the event is free to attend.
    type: boolean
    classification: public
  - handle: fixture_doors_on
    label: Doors on
    description: The day the doors open, when it is before the event starts.
    type: date
    classification: public
  - handle: fixture_organiser_email
    label: Organiser e-mail
    description: The e-mail address of the person who organises the event.
    type: text
    classification: internal
    agents: false
    format: email
  - handle: fixture_venue
    label: Venue
    description: Where the event takes place.
    type: group
    classification: public
    fields:
      - handle: fixture_venue_name
        label: Venue name
        description: The name of the venue.
        type: text
        required: true
      - handle: fixture_venue_address
        label: Venue address
        description: The address of the venue.
        type: group
        fields:
          - handle: fixture_venue_street
            label: Street
            description: The street and number.
            type: text
            required: true
          - handle: fixture_venue_postcode
            label: Postcode
            description: The postcode.
            type: text
            min_length: 4
            max_length: 10
```

The test that lists the workbench's schema exactly, and reads the new type as its blueprint says:

<!-- example: tests/Feature/Workbench/WorkbenchSchemaTest.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Workbench;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\RichTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/*
 * The workbench's own schema, listed exactly (GUARDRAILS 2.4, 2.6): the blueprint files of its app
 * root, each type's blueprint where no kernel test reads it, and every file cms:generate writes from
 * them. The kernel's modules test the workbench generically (packages/generators/tests/WorkbenchFixtureTest.php
 * finds fixture_article and fixture_measurement by handle and compares the generated files with the
 * committed ones), so a type added as a schema file changes the workbench and this file only,
 * never a file below packages/.
 */

/**
 * @return list<string>
 */
function workbenchSchemaFiles(string $directory): array
{
    $files = array_values(array_filter(
        scandir($directory) ?: [],
        static fn (string $file): bool => is_file($directory.'/'.$file),
    ));
    sort($files, SORT_STRING);

    return $files;
}

it('holds a blueprint file per fixture type in the app root', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());

    expect(workbenchSchemaFiles($target->roots[0]->path()))->toBe(['fixture_article.yaml', 'fixture_event.yaml', 'fixture_measurement.yaml'])
        ->and(array_map(
            static fn (TypeBlueprint $type): string => $type->owner->value.':'.$type->handle->value,
            app(BlueprintSource::class)->read($target->roots)->types,
        ))->toBe(['app:fixture_article', 'app:fixture_event', 'app:fixture_measurement']);
});

it('reads the third fixture type, added as a schema file alone', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $types = app(BlueprintSource::class)->read($target->roots)->types;
    $article = array_find($types, static fn (TypeBlueprint $type): bool => $type->handle->value === 'fixture_article');
    $event = array_find($types, static fn (TypeBlueprint $type): bool => $type->handle->value === 'fixture_event');
    $measurement = array_find($types, static fn (TypeBlueprint $type): bool => $type->handle->value === 'fixture_measurement');

    if ($article === null || $event === null || $measurement === null) {
        throw new LogicException('The workbench lacks one of its three fixture types.');
    }

    // The third fixture type, added as a schema file alone (MILESTONES M1 exit criteria), with
    // capabilities neither other type has: history audit-only, stages none and a route.
    expect($event->handle->value)->toBe('fixture_event')
        ->and($event->owner->value)->toBe('app')
        ->and($event->typeId->equals($article->typeId) || $event->typeId->equals($measurement->typeId))->toBeFalse()
        ->and([$event->capabilities->history, $event->capabilities->stages, $event->capabilities->localization, $event->capabilities->routable])
        ->toBe([History::AuditOnly, Stages::None, Localization::None, true])
        ->and(array_map(static fn (FieldBlueprint $field): array => [$field->handle->value, $field->options::class, $field->classification, $field->required], $event->fields))
        ->toBe([
            ['fixture_name', TextOptions::class, Classification::Public, true],
            ['fixture_starts_at', DatetimeOptions::class, Classification::Public, true],
            ['fixture_kind', SelectOptions::class, Classification::Public, true],
            ['fixture_seats', IntegerOptions::class, Classification::Public, false],
            ['fixture_price', DecimalOptions::class, Classification::Public, false],
            ['fixture_summary', LongTextOptions::class, Classification::Public, false],
            ['fixture_programme', RichTextOptions::class, Classification::Public, false],
            ['fixture_free', BooleanOptions::class, Classification::Public, false],
            ['fixture_doors_on', DateOptions::class, Classification::Public, false],
            ['fixture_organiser_email', TextOptions::class, Classification::Internal, false],
            ['fixture_venue', GroupOptions::class, Classification::Public, false],
        ]);
});

it('generates exactly these files from the workbench\'s schema', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $paths = app(GeneratorRunner::class)->run(DescriptorCompiler::compile(SchemaResolver::resolve(app(BlueprintSource::class)->read($target->roots))), $target)->paths();

    expect($paths)->toBe([
        'workbench/app/Cms/Generated/Boundary/AppFixtureArticleCodecV1.php',
        'workbench/app/Cms/Generated/Boundary/AppFixtureEventCodecV1.php',
        'workbench/app/Cms/Generated/Boundary/AppFixtureMeasurementCodecV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1Ext.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1ExtFixtureaddon.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1FixtureEmbargo.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1FixtureSources.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureEventV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureEventV1FixtureVenue.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureEventV1FixtureVenueFixtureVenueAddress.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1FixtureSensor.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1FixtureSeries.php',
        'workbench/app/Cms/Generated/GeneratedRecordCodecs.php',
        'workbench/app/Cms/Generated/GeneratedTypeCatalog.php',
        'workbench/app/Cms/Generated/GeneratedTypeValidators.php',
        'workbench/app/Cms/Generated/GeneratedTypesServiceProvider.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureArticle/AppFixtureArticleFilterField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureArticle/AppFixtureArticleQuery.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureArticle/AppFixtureArticleSortField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureEvent/AppFixtureEventFilterField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureEvent/AppFixtureEventQuery.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureEvent/AppFixtureEventSortField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureMeasurement/AppFixtureMeasurementFilterField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureMeasurement/AppFixtureMeasurementQuery.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureMeasurement/AppFixtureMeasurementSortField.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticle.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleExt.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFixtureaddonExt.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFixtureaddonExtension.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFixtureaddonFields.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleRecord.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleRecordFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureEmbargoGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureSourcesItem.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureTopicsChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/AppFixtureEvent.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/AppFixtureEventFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/AppFixtureEventRecord.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/AppFixtureEventRecordFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/FixtureKindChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/FixtureVenueFixtureVenueAddressGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/FixtureVenueGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/AppFixtureMeasurement.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/AppFixtureMeasurementFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/AppFixtureMeasurementRecord.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/AppFixtureMeasurementRecordFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureAlertsChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureScaleChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureSensorGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureSeriesItem.php',
        'workbench/app/Cms/Generated/TypeHandle.php',
        'workbench/app/Cms/Generated/Validators/AppFixtureArticleValidator.php',
        'workbench/app/Cms/Generated/Validators/AppFixtureEventValidator.php',
        'workbench/app/Cms/Generated/Validators/AppFixtureMeasurementValidator.php',
        'workbench/database/migrations/cms/app__fixture_article.lock',
        'workbench/database/migrations/cms/app__fixture_article_0001_create.php',
        'workbench/database/migrations/cms/app__fixture_article_0002_add_columns.php',
        'workbench/database/migrations/cms/app__fixture_article_0003_add_columns.php',
        'workbench/database/migrations/cms/app__fixture_event.lock',
        'workbench/database/migrations/cms/app__fixture_event_0001_create.php',
        'workbench/database/migrations/cms/app__fixture_measurement.lock',
        'workbench/database/migrations/cms/app__fixture_measurement_0001_create.php',
        'workbench/resources/js/cms/generated/index.ts',
        'workbench/resources/js/cms/generated/protocol/CreateEntryV1.ts',
        'workbench/resources/js/cms/generated/protocol/CreatePlacementV1.ts',
        'workbench/resources/js/cms/generated/protocol/DeactivateActorV1.ts',
        'workbench/resources/js/cms/generated/protocol/EnvelopeV1.ts',
        'workbench/resources/js/cms/generated/protocol/ProblemV1.ts',
        'workbench/resources/js/cms/generated/protocol/PublishEntryV1.ts',
        'workbench/resources/js/cms/generated/protocol/ReceiptV1.ts',
        'workbench/resources/js/cms/generated/protocol/ReleaseVariantV1.ts',
        'workbench/resources/js/cms/generated/protocol/ReviseEntryV1.ts',
        'workbench/resources/js/cms/generated/protocol/SetPlacementWindowV1.ts',
        'workbench/resources/js/cms/generated/protocol/UnpublishEntryV1.ts',
        'workbench/resources/js/cms/generated/records/AppFixtureArticleV1.ts',
        'workbench/resources/js/cms/generated/records/AppFixtureEventV1.ts',
        'workbench/resources/js/cms/generated/records/AppFixtureMeasurementV1.ts',
        'workbench/resources/js/cms/generated/validation.ts',
    ]);
});
```

The type also runs end to end in `tests/Postgres/WalkingSkeleton/ThirdTypeTest.php`: created, revised, placed and published through the command pipeline and `cms:run`, resolved over `GET /v1/resolve`, its fragment invalidated by the event runner, and its type table rebuilt from its head snapshots. Write such a test when a new type brings a combination of capabilities the others do not have.
