<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Editor\Domain\EditorLine;
use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
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
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Illuminate\Contracts\Config\Repository;

/*
 * The workbench's schema root and its committed generated code (GUARDRAILS 2.6): fixture_article
 * with history full and stages draft-release, and fixture_measurement with history none, stages
 * none, no route and other fields, the two fixture types of milestone 1, which together use every
 * core field type, so the workbench's record codecs are tested with each. Every file in
 * workbench/schema is a blueprint v1 file that YamlBlueprintSource reads without problems, and the
 * committed files are exactly what the generators produce from them: the same check as
 * `composer check:generated`, without writing. Every file starts with the editor line that
 * cms:schema:editor writes, so running it changes nothing.
 */

it('holds only blueprint v1 files, which the YAML source reads without problems', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $directory = $target->roots[0]->path();
    $files = SchemaFixtures::files($directory);

    $blueprints = app(BlueprintSource::class)->read($target->roots);

    expect(app(BlueprintSource::class))->toBeInstanceOf(YamlBlueprintSource::class)
        ->and(array_map(static fn (SchemaRoot $root): string => $root->owner->value.': '.$root->directory, $target->roots))->toBe(['app: workbench/schema'])
        ->and($files)->toBe(['fixture_article.yaml', 'fixture_measurement.yaml']);

    foreach ($files as $file) {
        expect((string) file_get_contents($directory.'/'.$file))->toMatch('/^blueprint: 1$/m');
    }

    expect($blueprints->extensions)->toBe([])
        ->and($blueprints->types)->toHaveCount(2);

    [$article, $measurement] = $blueprints->types;

    expect($article->handle->value)->toBe('fixture_article')
        ->and($article->owner->value)->toBe('app')
        ->and($article->version)->toBe(1)
        ->and($article->typeId->value->value)->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/')
        ->and([$article->capabilities->history, $article->capabilities->stages, $article->capabilities->localization, $article->capabilities->routable])
        ->toBe([History::Full, Stages::DraftRelease, Localization::None, true])
        ->and(array_map(static fn (FieldBlueprint $field): array => [$field->handle->value, $field->options::class, $field->classification, $field->description !== null], $article->fields))
        ->toBe([
            ['fixture_title', TextOptions::class, Classification::Public, true],
            ['fixture_body', RichTextOptions::class, Classification::Public, true],
            ['fixture_published_on', DateOptions::class, Classification::Public, true],
            ['fixture_reading_minutes', IntegerOptions::class, Classification::Public, true],
            ['fixture_featured', BooleanOptions::class, Classification::Public, true],
            ['fixture_topics', SelectOptions::class, Classification::Public, true],
            ['fixture_sources', GroupOptions::class, Classification::Internal, true],
            ['fixture_embargo', GroupOptions::class, Classification::Confidential, false],
        ]);

    expect($measurement->handle->value)->toBe('fixture_measurement')
        ->and($measurement->owner->value)->toBe('app')
        ->and($measurement->typeId->equals($article->typeId))->toBeFalse()
        ->and([$measurement->capabilities->history, $measurement->capabilities->stages, $measurement->capabilities->localization, $measurement->capabilities->routable])
        ->toBe([History::None, Stages::None, Localization::None, false])
        ->and(array_map(static fn (FieldBlueprint $field): array => [$field->handle->value, $field->options::class, $field->classification, $field->required], $measurement->fields))
        ->toBe([
            ['fixture_reading', DecimalOptions::class, Classification::Public, true],
            ['fixture_scale', SelectOptions::class, Classification::Public, true],
            ['fixture_measured_at', DatetimeOptions::class, Classification::Public, true],
            ['fixture_station', TextOptions::class, Classification::Internal, false],
            ['fixture_note', LongTextOptions::class, Classification::Internal, false],
            ['fixture_samples', IntegerOptions::class, Classification::Public, false],
            ['fixture_calibrated', BooleanOptions::class, Classification::Public, false],
            ['fixture_calibrated_on', DateOptions::class, Classification::Public, false],
            ['fixture_alerts', SelectOptions::class, Classification::Public, false],
            ['fixture_remark', RichTextOptions::class, Classification::Public, false],
            ['fixture_sensor', GroupOptions::class, Classification::Internal, false],
            ['fixture_series', GroupOptions::class, Classification::Public, false],
        ])
        ->and(array_intersect(
            array_map(static fn (FieldBlueprint $field): string => $field->handle->value, $measurement->fields),
            array_map(static fn (FieldBlueprint $field): string => $field->handle->value, $article->fields),
        ))->toBe([]);

    $fieldTypes = array_values(array_unique(array_map(
        static fn (FieldBlueprint $field): string => $field->options->typeName(),
        [...$article->fields, ...$measurement->fields],
    )));
    $coreFieldTypes = new FieldTypeRegistry(new CoreFieldTypes)->names();
    sort($fieldTypes, SORT_STRING);
    sort($coreFieldTypes, SORT_STRING);

    expect($fieldTypes)->toBe($coreFieldTypes, 'The fixture types use every core field type, so the workbench\'s codecs are tested with each.');
});

it('has committed generated code that matches the schema', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $schema = DescriptorCompiler::compile(SchemaResolver::resolve(app(BlueprintSource::class)->read($target->roots)));
    $result = app(GeneratorRunner::class)->run($schema, $target);

    expect($result->paths())->toBe([
        'workbench/app/Cms/Generated/Boundary/AppFixtureArticleCodecV1.php',
        'workbench/app/Cms/Generated/Boundary/AppFixtureMeasurementCodecV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1FixtureEmbargo.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1FixtureSources.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1FixtureSensor.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1FixtureSeries.php',
        'workbench/app/Cms/Generated/GeneratedTypeCatalog.php',
        'workbench/app/Cms/Generated/GeneratedTypeValidators.php',
        'workbench/app/Cms/Generated/GeneratedTypesServiceProvider.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticle.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleRecord.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleRecordFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureEmbargoGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureSourcesItem.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureTopicsChoice.php',
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
        'workbench/app/Cms/Generated/Validators/AppFixtureMeasurementValidator.php',
        'workbench/resources/js/cms/generated/index.ts',
    ]);

    foreach ($result->files as $file) {
        expect(file_get_contents($target->root.'/'.$file->path))->toBe($file->contents, $file->path.' differs from what the schema generates. Run `vendor/bin/testbench cms:generate`.');
    }
});

it('starts every file with the editor line, through the root to the blueprint schema of cboxdk/cms', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $directory = $target->roots[0]->path();
    $schema = new BlueprintSchemaFile()->editorPath();
    $files = SchemaFixtures::files($directory);

    expect($files)->not->toBe([]);

    foreach ($files as $file) {
        $contents = (string) file_get_contents($directory.'/'.$file);
        $first = explode("\n", $contents, 2)[0];

        expect($first)->toStartWith(EditorLine::PREFIX, $file.' does not start with the editor line. Run `vendor/bin/testbench cms:schema:editor`.');

        $path = substr($first, strlen(EditorLine::PREFIX));

        expect($path)->toBe('../../packages/contracts/resources/schemas/blueprint.v1.json')
            ->and(realpath(dirname($directory.'/'.$file).'/'.$path))->toBe(realpath(dirname(__DIR__, 3).'/packages/contracts/resources/schemas/blueprint.v1.json'))
            ->and(EditorLine::towards($schema, (string) realpath(dirname($directory.'/'.$file)))->apply($contents))->toBe($contents, $file.' is not what cms:schema:editor writes. Run `vendor/bin/testbench cms:schema:editor`.');
    }
});
