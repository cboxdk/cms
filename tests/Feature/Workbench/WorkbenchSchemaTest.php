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
        'workbench/resources/js/cms/generated/protocol/DeliveryExplanationV1.ts',
        'workbench/resources/js/cms/generated/protocol/DeliveryFragmentV1.ts',
        'workbench/resources/js/cms/generated/protocol/DeliveryV1.ts',
        'workbench/resources/js/cms/generated/protocol/EnvelopeV1.ts',
        'workbench/resources/js/cms/generated/protocol/ExplainedPathV1.ts',
        'workbench/resources/js/cms/generated/protocol/PathExplanationV1.ts',
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
