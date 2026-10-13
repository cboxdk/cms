<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs;

use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\TypeEditorCodecV1;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Cbox\Cms\Tests\Support\TypeScript\EntryFormValidation;
use Cbox\Cms\Tests\Support\TypeScript\TypeScriptValidators;
use Cbox\Cms\Tests\Support\Validation\WorkbenchMeasurementFixtures;
use LogicException;
use Workbench\App\Cms\Generated\GeneratedTypeEditors;
use Workbench\App\Cms\Generated\Validators\AppFixtureMeasurementValidator;

/*
 * The client validation of the component kit's EntryForm, the generated TypeScript validator of
 * the record and the kernel's InputValidator give the same verdict (PRD 11.8, 11.12; GUARDRAILS
 * 2.2): every validator fixture of the workbench's app:fixture_measurement, which has every core
 * field type and every rule for it, WorkbenchMeasurementFixtures, is fed to all three, EntryForm's
 * validateEntry() with the type's editor descriptor as type.describe answers it, the record's
 * validator with the entry's id beside the fields, and the InputValidator with the type's
 * generated rules, and all three give the same first error, its code and its path, or none.
 *
 * Two kinds of fixture are outside the comparison, each named in OUTSIDE_PARITY with the reason:
 * a key the type does not have, which the validation runtime reports at the object that holds it
 * as the kernel's codecs do, and the InputValidator at the key, and which an editor form, holding
 * only the type's fields, never writes; and a whole number written with a fraction, which JSON
 * gives JavaScript as the integer it equals.
 */

const RECORD_ID = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02';

const OUTSIDE_PARITY = [
    'integer: wrong type, a fraction' => 'JSON gives JavaScript 5.0 as the integer 5, so no validator in a browser can tell it from 5.',
    'group: a nested field it does not have' => 'The runtime reports an unknown key at its object, as the codecs do; an editor form never writes one.',
    'repeated group: a nested field it does not have' => 'The runtime reports an unknown key at its object, as the codecs do; an editor form never writes one.',
    'a field the type does not have' => 'The runtime reports an unknown key at its object, as the codecs do; an editor form never writes one.',
    'an extension namespace the type does not have' => 'The runtime reports an unknown key at its object, as the codecs do; an editor form never writes one.',
];

/**
 * The editor descriptor of app:fixture_measurement, as type.describe answers a reader who may read
 * every field.
 */
function measurementDescriptor(): string
{
    $editor = new GeneratedTypeEditors()->named(new TypeName('app:fixture_measurement'));

    if (! $editor instanceof TypeEditor) {
        throw new LogicException('The workbench has no type app:fixture_measurement.');
    }

    return new TypeEditorCodecV1()->encode($editor, ClassificationAccess::Sensitive);
}

/**
 * Every validator fixture of the workbench's measurement inside the comparison, by name: the
 * fields, as JSON gives them.
 *
 * @return array<string, array<array-key, mixed>>
 */
function parityFixtures(): array
{
    $fixtures = ['every rule met' => WorkbenchMeasurementFixtures::valid()];

    foreach (WorkbenchMeasurementFixtures::brokenRules() as $name => [$path, $value]) {
        if (! array_key_exists($name, OUTSIDE_PARITY)) {
            $fixtures[$name] = WorkbenchMeasurementFixtures::withValue(WorkbenchMeasurementFixtures::valid(), $path, $value);
        }
    }

    foreach (['fixture_reading', 'fixture_scale', 'fixture_measured_at'] as $field) {
        $fixtures['required: '.$field] = WorkbenchMeasurementFixtures::withValue(WorkbenchMeasurementFixtures::valid(), $field, null, remove: true);
    }

    foreach (WorkbenchMeasurementFixtures::conditionalItems() as $name => [$item, $measurement]) {
        $fixtures['conditional: '.$name] = [...WorkbenchMeasurementFixtures::valid(), ...$measurement, 'fixture_series' => [['fixture_series_value' => '1', ...$item]]];
    }

    return $fixtures;
}

/**
 * The first error as `path: code`, or `valid`.
 */
function firstError(?string $path, ?string $code): string
{
    return $code === null ? 'valid' : ($path ?? '(fields)').': '.$code;
}

it('names only fixtures the workbench has as outside the comparison', function (): void {
    expect(array_diff(array_keys(OUTSIDE_PARITY), array_keys(WorkbenchMeasurementFixtures::brokenRules())))->toBe([]);
});

it('gives the same code and path in EntryForm, the generated TypeScript validator and the InputValidator for every fixture', function (): void {
    $fixtures = parityFixtures();
    $rules = new AppFixtureMeasurementValidator()->rules();
    $form = EntryFormValidation::run(measurementDescriptor(), array_values(array_map(
        static fn (array $fields): array => ['fields' => $fields, 'stage' => 'write'],
        $fixtures,
    )));
    $records = TypeScriptValidators::run('workbench/resources/js/cms/generated', array_values(array_map(
        static fn (array $fields): array => [
            'module' => 'records/AppFixtureMeasurementV1',
            'validator' => 'validateAppFixtureMeasurementV1',
            'document' => json_encode(['cms_id' => RECORD_ID, ...$fields], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        ],
        $fixtures,
    )));

    expect(count($fixtures))->toBeGreaterThan(50);

    foreach (array_keys($fixtures) as $index => $name) {
        $kernel = new InputValidator()->validate($rules, $fixtures[$name])->errors[0] ?? null;
        $expected = firstError($kernel?->path?->toString(), $kernel?->code->value);
        $issue = $form[$index][0] ?? null;
        $record = $records[$index];

        expect(firstError($issue['path'] ?? null, $issue['code'] ?? null))->toBe($expected, 'EntryForm on the fixture '.$name)
            ->and(firstError($record['path'] ?? null, $record['valid'] ? null : ($record['code'] ?? '')))->toBe($expected, 'The record validator on the fixture '.$name);
    }
});
