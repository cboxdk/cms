<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Validation;

use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Cbox\Cms\Tests\Support\Validation\WorkbenchMeasurementFixtures;
use Workbench\App\Cms\Generated\Validators\AppFixtureArticleValidator;
use Workbench\App\Cms\Generated\Validators\AppFixtureMeasurementValidator;

/*
 * The runtime validators cms:generate writes for the workbench's fixture types (PRD 11.8, 11.12,
 * MILESTONES M1 point 2), fed input as the REST API, MCP or the CLI would send it: valid input
 * passes, and for every field type one invalid input per rule gives exactly one field error with
 * the value's path and the rule's code from the error catalog. fixture_measurement has every core
 * field type and every rule; its blueprint is workbench/schema/fixture_measurement.yaml. The
 * conditional fields of its series' items (gap G3) are never required while hidden, and a value for
 * one that is hidden and clear is validation_hidden_field.
 */

/**
 * The errors of a report as `path: code`.
 *
 * @return list<string>
 */
function fieldErrors(ValidationReport $report): array
{
    return array_map(static fn (CatalogError $error): string => ($error->path?->toString() ?? '(input)').': '.$error->code->value, $report->errors);
}

it('passes input that meets every rule, and input with only the required fields', function (): void {
    $validator = new InputValidator;
    $rules = new AppFixtureMeasurementValidator()->rules();
    $required = ['fixture_reading' => '1', 'fixture_scale' => 'fixture_kelvin', 'fixture_measured_at' => '2026-09-29T10:00:00Z'];

    expect(fieldErrors($validator->validate($rules, WorkbenchMeasurementFixtures::valid())))->toBe([])
        ->and(fieldErrors($validator->validate($rules, $required)))->toBe([])
        ->and(fieldErrors($validator->validate($rules, [...$required, 'fixture_station' => null, 'fixture_sensor' => null])))->toBe([]);
});

it('gives the type id of the blueprint', function (): void {
    expect(new AppFixtureMeasurementValidator()->type()->toString())->toBe('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a6b')
        ->and(new AppFixtureArticleValidator()->type()->toString())->toBe('01a0df3e-8cef-7e9f-8daf-9faa60f1faa6');
});

it('gives one field error with the path and the rule\'s code for each broken rule', function (string $path, mixed $value, string $at, string $code): void {
    $report = new InputValidator()->validate(new AppFixtureMeasurementValidator()->rules(), WorkbenchMeasurementFixtures::withValue(WorkbenchMeasurementFixtures::valid(), $path, $value), at: new FieldPath('fields'));

    expect(fieldErrors($report))->toBe(['fields.'.$at.': '.$code])
        ->and($report->passed())->toBeFalse()
        ->and($report->code()->value)->toBe('validation_failed');
})->with(WorkbenchMeasurementFixtures::brokenRules());

it('requires each required field, with its path', function (string $field): void {
    $report = new InputValidator()->validate(new AppFixtureMeasurementValidator()->rules(), WorkbenchMeasurementFixtures::withValue(WorkbenchMeasurementFixtures::valid(), $field, null, remove: true));

    expect(fieldErrors($report))->toBe([$field.': validation_required']);
})->with(['fixture_reading', 'fixture_scale', 'fixture_measured_at']);

it('validates the article\'s title and Portable Text body', function (): void {
    $rules = new AppFixtureArticleValidator()->rules();
    $body = [['_type' => 'block', '_key' => 'a', 'style' => 'h2', 'markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'https://example.com/']], 'children' => [['_type' => 'span', '_key' => 's', 'text' => 'Title', 'marks' => ['em', 'l']]]]];

    expect(fieldErrors(new InputValidator()->validate($rules, ['fixture_title' => 'News', 'fixture_featured' => true, 'fixture_body' => $body])))->toBe([])
        ->and(fieldErrors(new InputValidator()->validate($rules, ['fixture_title' => str_repeat('x', 256), 'fixture_featured' => false])))->toBe(['fixture_title: validation_too_long']);
});

it('collects every error of the input in one report, in the order of the fields', function (): void {
    $input = WorkbenchMeasurementFixtures::withValue(WorkbenchMeasurementFixtures::withValue(WorkbenchMeasurementFixtures::withValue(WorkbenchMeasurementFixtures::valid(), 'fixture_samples', 0), 'fixture_scale', 'fixture_fahrenheit'), 'fixture_reading', null, remove: true);

    expect(fieldErrors(new InputValidator()->validate(new AppFixtureMeasurementValidator()->rules(), $input)))->toBe([
        'fixture_reading: validation_required',
        'fixture_samples: validation_below_minimum',
        'fixture_scale: validation_not_an_option',
    ]);
});

it('never requires a hidden field, and refuses a value on a hidden field marked clear, with the codes of the catalog', function (array $item, array $measurement, array $expected): void {
    $input = [...WorkbenchMeasurementFixtures::valid(), ...$measurement, 'fixture_series' => [['fixture_series_value' => '1', ...$item]]];

    expect(fieldErrors(new InputValidator()->validate(new AppFixtureMeasurementValidator()->rules(), $input)))->toBe($expected);
})->with(WorkbenchMeasurementFixtures::conditionalItems());

it('never requires a hidden field at the release stage either', function (): void {
    $input = [...WorkbenchMeasurementFixtures::valid(), 'fixture_series' => [['fixture_series_value' => '1', 'fixture_series_method' => 'fixture_logged']]];

    expect(fieldErrors(new InputValidator()->validate(new AppFixtureMeasurementValidator()->rules(), $input, ValidationStage::Release)))->toBe([]);
});
