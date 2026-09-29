<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Validation;

use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Workbench\App\Cms\Generated\Validators\AppFixtureArticleValidator;
use Workbench\App\Cms\Generated\Validators\AppFixtureMeasurementValidator;

/*
 * The runtime validators cms:generate writes for the workbench's fixture types (PRD 11.8, 11.12,
 * MILESTONES M1 point 2), fed input as the REST API, MCP or the CLI would send it: valid input
 * passes, and for every field type one invalid input per rule gives exactly one field error with
 * the value's path and the rule's code from the error catalog. fixture_measurement has every core
 * field type and every rule; its blueprint is workbench/schema/fixture_measurement.yaml.
 */

/**
 * Input for fixture_measurement that meets every rule, with every field set.
 *
 * @return array<string, mixed>
 */
function validMeasurement(): array
{
    return [
        'fixture_reading' => '-273.150',
        'fixture_scale' => 'fixture_celsius',
        'fixture_measured_at' => '2026-09-29T12:00:00+02:00',
        'fixture_station' => 'CPH-01',
        'fixture_note' => "Two lines\nof note.",
        'fixture_samples' => 1000,
        'fixture_calibrated' => false,
        'fixture_calibrated_on' => '2000-01-01',
        'fixture_alerts' => ['fixture_low', 'fixture_drift'],
        'fixture_remark' => [
            [
                '_type' => 'block',
                '_key' => 'b1',
                'style' => 'normal',
                'listItem' => 'bullet',
                'level' => 1,
                'children' => [['_type' => 'span', '_key' => 's1', 'text' => 'Checked', 'marks' => ['strong']]],
            ],
        ],
        'fixture_sensor' => [
            'fixture_sensor_code' => 'S-7',
            'fixture_sensor_contact' => 'sensors@example.com',
            'fixture_sensor_manual' => 'https://example.com/manual',
        ],
        'fixture_series' => [
            ['fixture_series_value' => '-100.00', 'fixture_series_taken_at' => '2000-01-01T00:00:00Z'],
            ['fixture_series_value' => '1000'],
        ],
    ];
}

/**
 * The errors of a report as `path: code`.
 *
 * @return list<string>
 */
function fieldErrors(ValidationReport $report): array
{
    return array_map(static fn (CatalogError $error): string => ($error->path?->toString() ?? '(input)').': '.$error->code->value, $report->errors);
}

/**
 * Sets the value at a path of dot-separated names and indexes in the input, or removes it.
 *
 * @param  array<array-key, mixed>  $input
 * @return array<array-key, mixed>
 */
function withValue(array $input, string $path, mixed $value, bool $remove = false): array
{
    $segments = explode('.', $path);
    $key = array_shift($segments);
    $key = ctype_digit($key) ? (int) $key : $key;

    if ($segments === []) {
        if ($remove) {
            unset($input[$key]);
        } else {
            $input[$key] = $value;
        }

        return $input;
    }

    $inner = $input[$key] ?? [];
    $input[$key] = withValue(is_array($inner) ? $inner : [], implode('.', $segments), $value, $remove);

    return $input;
}

it('passes input that meets every rule, and input with only the required fields', function (): void {
    $validator = new InputValidator;
    $rules = new AppFixtureMeasurementValidator()->rules();
    $required = ['fixture_reading' => '1', 'fixture_scale' => 'fixture_kelvin', 'fixture_measured_at' => '2026-09-29T10:00:00Z'];

    expect(fieldErrors($validator->validate($rules, validMeasurement())))->toBe([])
        ->and(fieldErrors($validator->validate($rules, $required)))->toBe([])
        ->and(fieldErrors($validator->validate($rules, [...$required, 'fixture_station' => null, 'fixture_sensor' => null])))->toBe([]);
});

it('gives the type id of the blueprint', function (): void {
    expect(new AppFixtureMeasurementValidator()->type()->toString())->toBe('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a6b')
        ->and(new AppFixtureArticleValidator()->type()->toString())->toBe('01a0df3e-8cef-7e9f-8daf-9faa60f1faa6');
});

it('gives one field error with the path and the rule\'s code for each broken rule', function (string $path, mixed $value, string $at, string $code): void {
    $report = new InputValidator()->validate(new AppFixtureMeasurementValidator()->rules(), withValue(validMeasurement(), $path, $value), at: new FieldPath('fields'));

    expect(fieldErrors($report))->toBe(['fields.'.$at.': '.$code])
        ->and($report->passed())->toBeFalse()
        ->and($report->code()->value)->toBe('validation_failed');
})->with([
    // decimal: fixture_reading, precision 12 and scale 3
    'decimal: wrong type, a JSON number' => ['fixture_reading', 1.5, 'fixture_reading', 'validation_wrong_type'],
    'decimal: wrong type, not a number' => ['fixture_reading', '1,5', 'fixture_reading', 'validation_wrong_type'],
    'decimal: too many digits after the point' => ['fixture_reading', '1.2345', 'fixture_reading', 'validation_too_many_digits'],
    'decimal: too many digits before the point' => ['fixture_reading', '1234567890', 'fixture_reading', 'validation_too_many_digits'],
    // select, one choice: fixture_scale
    'select: wrong type' => ['fixture_scale', 1, 'fixture_scale', 'validation_wrong_type'],
    'select: not an option' => ['fixture_scale', 'fixture_fahrenheit', 'fixture_scale', 'validation_not_an_option'],
    // datetime: fixture_measured_at
    'datetime: wrong type, a date' => ['fixture_measured_at', '2026-09-29', 'fixture_measured_at', 'validation_wrong_type'],
    'datetime: wrong type, no offset' => ['fixture_measured_at', '2026-09-29T12:00:00', 'fixture_measured_at', 'validation_wrong_type'],
    // text: fixture_station, 3 to 40 characters
    'text: wrong type' => ['fixture_station', 40, 'fixture_station', 'validation_wrong_type'],
    'text: too short' => ['fixture_station', 'CP', 'fixture_station', 'validation_too_short'],
    'text: too long' => ['fixture_station', str_repeat('x', 41), 'fixture_station', 'validation_too_long'],
    // long text: fixture_note, at most 10,000 characters
    'long text: wrong type' => ['fixture_note', ['a'], 'fixture_note', 'validation_wrong_type'],
    'long text: too long' => ['fixture_note', str_repeat('x', 10001), 'fixture_note', 'validation_too_long'],
    // integer: fixture_samples, 1 to 1000
    'integer: wrong type, a string' => ['fixture_samples', '5', 'fixture_samples', 'validation_wrong_type'],
    'integer: wrong type, a fraction' => ['fixture_samples', 5.0, 'fixture_samples', 'validation_wrong_type'],
    'integer: below the minimum' => ['fixture_samples', 0, 'fixture_samples', 'validation_below_minimum'],
    'integer: above the maximum' => ['fixture_samples', 1001, 'fixture_samples', 'validation_above_maximum'],
    // boolean: fixture_calibrated
    'boolean: wrong type' => ['fixture_calibrated', 'yes', 'fixture_calibrated', 'validation_wrong_type'],
    // date: fixture_calibrated_on, 2000-01-01 to 2099-12-31
    'date: wrong type, no real day' => ['fixture_calibrated_on', '2026-02-30', 'fixture_calibrated_on', 'validation_wrong_type'],
    'date: below the minimum' => ['fixture_calibrated_on', '1999-12-31', 'fixture_calibrated_on', 'validation_below_minimum'],
    'date: above the maximum' => ['fixture_calibrated_on', '2100-01-01', 'fixture_calibrated_on', 'validation_above_maximum'],
    // select, several choices: fixture_alerts, 1 to 2 of them
    'multiple select: wrong type' => ['fixture_alerts', 'fixture_low', 'fixture_alerts', 'validation_wrong_type'],
    'multiple select: an item of the wrong type' => ['fixture_alerts', [1], 'fixture_alerts[0]', 'validation_wrong_type'],
    'multiple select: not an option' => ['fixture_alerts', ['fixture_low', 'fixture_storm'], 'fixture_alerts[1]', 'validation_not_an_option'],
    'multiple select: an option twice' => ['fixture_alerts', ['fixture_high', 'fixture_high'], 'fixture_alerts[1]', 'validation_duplicate_item'],
    'multiple select: too few items' => ['fixture_alerts', [], 'fixture_alerts', 'validation_too_few_items'],
    'multiple select: too many items' => ['fixture_alerts', ['fixture_low', 'fixture_high', 'fixture_drift'], 'fixture_alerts', 'validation_too_many_items'],
    // rich text: fixture_remark, the style normal, the mark strong, bullet lists and no links
    'rich text: wrong type' => ['fixture_remark', 'Checked', 'fixture_remark', 'validation_wrong_type'],
    'rich text: invalid structure' => ['fixture_remark.0._type', 'image', 'fixture_remark[0]._type', 'validation_invalid_rich_text'],
    'rich text: a style it does not allow' => ['fixture_remark.0.style', 'h2', 'fixture_remark[0].style', 'validation_rich_text_not_allowed'],
    'rich text: a mark it does not allow' => ['fixture_remark.0.children.0.marks', ['em'], 'fixture_remark[0].children[0].marks[0]', 'validation_rich_text_not_allowed'],
    'rich text: a list it does not allow' => ['fixture_remark.0.listItem', 'number', 'fixture_remark[0].listItem', 'validation_rich_text_not_allowed'],
    'rich text: a link it does not allow' => ['fixture_remark.0.markDefs', [['_type' => 'link', '_key' => 'l1', 'href' => 'https://example.com/']], 'fixture_remark[0].markDefs[0]._type', 'validation_rich_text_not_allowed'],
    // group once: fixture_sensor
    'group: wrong type' => ['fixture_sensor', ['S-7'], 'fixture_sensor', 'validation_wrong_type'],
    'group: a nested field it does not have' => ['fixture_sensor.fixture_sensor_colour', 'grey', 'fixture_sensor.fixture_sensor_colour', 'validation_unknown_field'],
    'group: a required nested field left out' => ['fixture_sensor.fixture_sensor_code', null, 'fixture_sensor.fixture_sensor_code', 'validation_required'],
    'group: a nested text not an email address' => ['fixture_sensor.fixture_sensor_contact', 'sensors.example.com', 'fixture_sensor.fixture_sensor_contact', 'validation_invalid_format'],
    'group: a nested text not a URL' => ['fixture_sensor.fixture_sensor_manual', 'ftp://example.com/manual', 'fixture_sensor.fixture_sensor_manual', 'validation_invalid_format'],
    // repeated group: fixture_series, 1 to 3 items
    'repeated group: wrong type' => ['fixture_series', ['fixture_series_value' => '1'], 'fixture_series', 'validation_wrong_type'],
    'repeated group: an item of the wrong type' => ['fixture_series.1', '1', 'fixture_series[1]', 'validation_wrong_type'],
    'repeated group: too few items' => ['fixture_series', [], 'fixture_series', 'validation_too_few_items'],
    'repeated group: too many items' => ['fixture_series', array_fill(0, 4, ['fixture_series_value' => '1']), 'fixture_series', 'validation_too_many_items'],
    'repeated group: a required nested field left out' => ['fixture_series.1.fixture_series_value', null, 'fixture_series[1].fixture_series_value', 'validation_required'],
    'repeated group: a nested decimal below the minimum' => ['fixture_series.0.fixture_series_value', '-100.01', 'fixture_series[0].fixture_series_value', 'validation_below_minimum'],
    'repeated group: a nested decimal above the maximum' => ['fixture_series.0.fixture_series_value', '1000.01', 'fixture_series[0].fixture_series_value', 'validation_above_maximum'],
    'repeated group: a nested datetime below the minimum' => ['fixture_series.0.fixture_series_taken_at', '2000-01-01T00:59:59+01:00', 'fixture_series[0].fixture_series_taken_at', 'validation_below_minimum'],
    'repeated group: a nested datetime above the maximum' => ['fixture_series.0.fixture_series_taken_at', '2100-01-01T00:00:00Z', 'fixture_series[0].fixture_series_taken_at', 'validation_above_maximum'],
    'repeated group: a nested field it does not have' => ['fixture_series.0.fixture_series_unit', 'K', 'fixture_series[0].fixture_series_unit', 'validation_unknown_field'],
    // the type
    'a field the type does not have' => ['fixture_humidity', 40, 'fixture_humidity', 'validation_unknown_field'],
    'an extension namespace the type does not have' => ['ext.acme', ['fixture_humidity' => 40], 'ext.acme', 'validation_unknown_field'],
]);

it('requires each required field, with its path', function (string $field): void {
    $report = new InputValidator()->validate(new AppFixtureMeasurementValidator()->rules(), withValue(validMeasurement(), $field, null, remove: true));

    expect(fieldErrors($report))->toBe([$field.': validation_required']);
})->with(['fixture_reading', 'fixture_scale', 'fixture_measured_at']);

it('validates the article\'s title and Portable Text body', function (): void {
    $rules = new AppFixtureArticleValidator()->rules();
    $body = [['_type' => 'block', '_key' => 'a', 'style' => 'h2', 'markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'https://example.com/']], 'children' => [['_type' => 'span', '_key' => 's', 'text' => 'Title', 'marks' => ['em', 'l']]]]];

    expect(fieldErrors(new InputValidator()->validate($rules, ['fixture_title' => 'News', 'fixture_featured' => true, 'fixture_body' => $body])))->toBe([])
        ->and(fieldErrors(new InputValidator()->validate($rules, ['fixture_title' => str_repeat('x', 256), 'fixture_featured' => false])))->toBe(['fixture_title: validation_too_long']);
});

it('collects every error of the input in one report, in the order of the fields', function (): void {
    $input = withValue(withValue(withValue(validMeasurement(), 'fixture_samples', 0), 'fixture_scale', 'fixture_fahrenheit'), 'fixture_reading', null, remove: true);

    expect(fieldErrors(new InputValidator()->validate(new AppFixtureMeasurementValidator()->rules(), $input)))->toBe([
        'fixture_reading: validation_required',
        'fixture_samples: validation_below_minimum',
        'fixture_scale: validation_not_an_option',
    ]);
});
