---
title: Records and JSON codecs
weight: 45
description: "The record DTO and the JSON codec cms:generate writes for every type, the canonical JSON form of each kind of value, classification access and Omitted, the errors of decoding, and the JsonCodec contract."
---

# Records and JSON codecs

<!-- extension-point: Cbox\Cms\Contracts\Codecs\JsonCodec -->

Every projection hands out DTOs, never rows or models, and the DTO is the only contract that leaves the server (PRD 8.9). Its JSON form is generated too: one codec per contract version fixes how ids, enums, times, lists, `null` and missing fields are written, and nothing reflects on a class at run time (GUARDRAILS 2.2).

## What cms:generate writes

For each type below the schema roots, and for the record contract version 1, `cms:generate` writes into the PHP directory of `cbox-cms.generators` (`app/Cms/Generated` in an application):

- `Domain/Dto/<Owner><Handle>V1.php`: the record DTO, a final readonly class with the entry's id and every field of the type. The class name is the type's case of the `TypeHandle` enum with the version, such as `AppFixtureArticleV1` for `app:fixture_article`. A group is a DTO of its own, named after the class it is in and its handle, such as `AppFixtureArticleV1FixtureSources`, and a repeated group is a list of them. The fields that extensions add are under `ext`, one DTO per extender's namespace, as the code addresses them: `$record->ext->app->taxCode`.
- `Boundary/<Owner><Handle>CodecV1.php`: the codec, which implements `JsonCodec` for the record. It sits in a `Boundary` namespace, because it reads the `mixed` of a decoded document.

Both are committed, and `composer check:generated` fails when they are not what the blueprints generate. Two handles that give the same PHP name, such as `item_2` and `item2`, or a group and a type that give the same class, are refused with `generate_name_collision`, never renamed.

## The JSON form

A record is one JSON object: the entry's id under `cms_id` (a handle never starts with `cms_`), each of the owner's fields under its handle, and the extension fields under `ext`, by namespace and then by handle. The keys are sorted at every level and there is no whitespace, so one record always gives the same bytes.

| Field type | PHP | JSON |
|---|---|---|
| `text`, `long_text` | `string` | a string; its length is counted in characters |
| `integer` | `int` | an integer, never a float or a string |
| `decimal` | a numeric string | a string with exactly the field's scale of digits after the point, such as `"12.50"`, never a JSON number |
| `boolean` | `bool` | `true` or `false` |
| `date` | `DateTimeImmutable` | `"YYYY-MM-DD"` |
| `datetime` | `DateTimeImmutable` | RFC 3339 in UTC with six decimals, such as `"2026-01-01T12:00:00.000000Z"`; any offset is read |
| `select` | one of the values, or a list with `multiple` | the value, or a list of them |
| `rich_text` | a `ListValue` of Portable Text blocks | the blocks |
| `group` | a DTO of its fields, or a list with `repeat` | an object, or a list of objects |

An id is its canonical string. A field that holds `null` is written as `null`. A field the DTO holds as `Omitted` is left out.

## Classification access and Omitted

A field classified above public can be withheld (PRD 12.2). The codec takes the classification access of the call's `AccessContext`: `encode()` leaves out every field classified above it, and the DTO's `visibleTo()` gives the record as the caller may see it, with those fields `Omitted`. `Cbox\Cms\Contracts\Fields\Omitted` is never `null`: it says the DTO carries no value for the field, so code that reads it must handle the case, and no template mistakes a field it was not given for an empty one.

`decode()` reads a document with the caller's access. A field above the access must be absent and comes back `Omitted`; a document that gives one is refused. An optional field that is absent also comes back `Omitted`, and one that is `null` comes back `null`.

## Decoding checks every rule

`decode()` checks every rule the blueprint gives a field, from the type descriptor's rule set that the runtime validators share (PRD 11.12): lengths, formats, bounds, the options of a select, the number of items, the precision and scale of a decimal, and the styles, marks, list kinds and links of rich text. It throws `Cbox\Cms\Core\Codecs\Domain\DecodingFailed`, whose `errorCode` is one of two codes of the [error catalog](errors.md):

- `json_malformed`: the document is not well-formed JSON, not an object, nests too deep, or an object in it has the same key twice. `json_decode()` keeps the last of two equal keys; the codec refuses them.
- `json_invalid`: a key is missing or unknown, a value has the wrong type or breaks a rule, or a field is classified above the access. `path` is the `FieldPath` of the value, such as `fixture_sources[0].fixture_source_url`.

## The JsonCodec contract

`Cbox\Cms\Contracts\Codecs\JsonCodec` is the contract of every generated codec, with the DTO it encodes as its type argument: `encode(object $dto, ClassificationAccess $access): string` and `decode(string $json, ClassificationAccess $access): object`. A surface serves a DTO through the codec of the contract version it serves, with the classification access of the call:

<!-- example-file: examples/Unit/Codecs/ServedRecord.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Codecs;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\AccessContext;

/**
 * How a surface serves a DTO: through the codec of the contract version it serves, with the
 * classification access of the call's AccessContext, so a field above it never leaves the server.
 */
final readonly class ServedRecord
{
    /**
     * @template TDto of object
     *
     * @param  JsonCodec<TDto>  $codec
     * @param  TDto  $dto
     */
    public static function body(JsonCodec $codec, object $dto, AccessContext $context): string
    {
        return $codec->encode($dto, $context->classificationAccess);
    }
}
```

The workbench's fixture type `fixture_measurement` has an internal field, `fixture_station`, which an anonymous caller does not get:

<!-- example: examples/Unit/Codecs/RecordCodecTest.php -->
```php
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
```
