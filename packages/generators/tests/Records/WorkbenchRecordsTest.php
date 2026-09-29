<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Records;

use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeName;
use DateTimeImmutable;
use Workbench\App\Cms\Generated\GeneratedTypeCatalog;
use Workbench\App\Cms\Generated\Records\AppFixtureArticle\AppFixtureArticleFactory;
use Workbench\App\Cms\Generated\Records\AppFixtureArticle\AppFixtureArticleRecordFactory;
use Workbench\App\Cms\Generated\Records\AppFixtureMeasurement\AppFixtureMeasurement;
use Workbench\App\Cms\Generated\Records\AppFixtureMeasurement\AppFixtureMeasurementRecordFactory;
use Workbench\App\Cms\Generated\Records\AppFixtureMeasurement\FixtureScaleChoice;

/*
 * The workbench's generated code in the container, as an application gets it once it registers
 * the generated service provider: the TypeCatalog contract is the generated catalog, and each
 * type's record factory builds its composite record from the kernel's generic field values.
 */

it('binds the TypeCatalog contract to the generated catalog, once per application', function (): void {
    expect(app(TypeCatalog::class))->toBeInstanceOf(GeneratedTypeCatalog::class)
        ->and(app(TypeCatalog::class))->toBe(app(TypeCatalog::class))
        ->and(app(TypeCatalog::class)->named(new TypeName('app:fixture_measurement'))?->capabilities->routable)->toBeFalse();
});

it('binds each type\'s record factory to the factory of its composite record', function (): void {
    expect(app(AppFixtureArticleRecordFactory::class))->toBeInstanceOf(AppFixtureArticleFactory::class);

    $values = new FieldValues(new FieldMap(
        new NamedValue(new FieldHandle('fixture_measured_at'), new DateTimeValue(new DateTimeImmutable('2026-09-29T08:00:00Z'))),
        new NamedValue(new FieldHandle('fixture_reading'), new DecimalValue('21.5')),
        new NamedValue(new FieldHandle('fixture_scale'), new TextValue('fixture_celsius')),
    ));
    $record = app(AppFixtureMeasurementRecordFactory::class)->fromFieldValues($values);

    expect($record)->toBeInstanceOf(AppFixtureMeasurement::class)
        ->and($record->fixtureScale)->toBe(FixtureScaleChoice::FixtureCelsius)
        ->and($record->fixtureReading)->toBe('21.5')
        ->and($record->fixtureNote)->toBeNull();
});
