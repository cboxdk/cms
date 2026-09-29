<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Examples\Unit\Codecs\ServedRecord;
use Workbench\App\Cms\Generated\Boundary\AppFixtureMeasurementCodecV1;
use Workbench\App\Cms\Generated\Domain\Dto\AppFixtureMeasurementV1;

// The workbench's type fixture_measurement has an internal field, fixture_station. cms:generate
// wrote its record DTO and codec; the example serves a record to an anonymous caller, whose
// classification access is public, and reads a document back.

it('serves a record without the fields above the caller\'s classification access', function (): void {
    $record = new AppFixtureMeasurementV1(
        cmsId: EntryId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'),
        fixtureAlerts: Omitted::Field,
        fixtureCalibrated: Omitted::Field,
        fixtureCalibratedOn: Omitted::Field,
        fixtureMeasuredAt: new DateTimeImmutable('2026-03-10T13:00:00+01:00'),
        fixtureNote: null,
        fixtureReading: '21.5',
        fixtureRemark: Omitted::Field,
        fixtureSamples: Omitted::Field,
        fixtureScale: 'fixture_celsius',
        fixtureSensor: Omitted::Field,
        fixtureSeries: Omitted::Field,
        fixtureStation: 'DK-042',
    );

    expect(ServedRecord::body(new AppFixtureMeasurementCodecV1, $record, AccessContext::anonymous()))
        ->toBe('{"cms_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","fixture_measured_at":"2026-03-10T12:00:00.000000Z","fixture_reading":"21.500","fixture_scale":"fixture_celsius"}');
});

it('reads a document, with Omitted for what it leaves out, and refuses one that breaks a rule', function (): void {
    $codec = new AppFixtureMeasurementCodecV1;
    $record = $codec->decode(
        '{"cms_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","fixture_measured_at":"2026-03-10T12:00:00Z","fixture_reading":"21.5","fixture_scale":"fixture_kelvin"}',
        ClassificationAccess::Internal,
    );

    expect($record->fixtureReading)->toBe('21.500')
        ->and($record->fixtureStation)->toBe(Omitted::Field)
        ->and(static fn (): AppFixtureMeasurementV1 => $codec->decode(
            '{"cms_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","fixture_measured_at":"2026-03-10T12:00:00Z","fixture_reading":"21.5","fixture_scale":"fixture_fahrenheit"}',
            ClassificationAccess::Internal,
        ))->toThrow(DecodingFailed::class, '[json_invalid] fixture_scale: is not one of fixture_celsius, fixture_kelvin.');
});
