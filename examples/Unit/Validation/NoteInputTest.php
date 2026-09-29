<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Examples\Unit\Validation\NoteValidator;

// A surface checks a command's fields, as JSON decoded them, against the type's validator before
// the command goes further. Bad input gives field errors with paths and codes, never an exception.

it('passes valid input and reports each broken rule with its path and code', function (): void {
    $validator = new InputValidator;
    $input = json_decode('{"tags": ["task", "later"], "colour": "red"}', true);

    $report = $validator->validateWith(new NoteValidator, $input, at: new FieldPath('fields'));

    expect($validator->validateWith(new NoteValidator, ['title' => 'Buy milk'])->passed())->toBeTrue()
        ->and($report->passed())->toBeFalse()
        ->and($report->code()->value)->toBe('validation_failed')
        ->and(array_map(static fn (CatalogError $error): string => $error->path?->toString().': '.$error->code->value, $report->errors))->toBe([
            'fields.tags[1]: validation_not_an_option',
            'fields.title: validation_required',
            'fields.colour: validation_unknown_field',
        ]);
});

it('requires the extension field only when the entry is released', function (): void {
    $validator = new InputValidator;
    $input = ['title' => 'Buy milk'];

    expect($validator->validateWith(new NoteValidator, $input, ValidationStage::Write)->passed())->toBeTrue()
        ->and($validator->validateWith(new NoteValidator, $input, ValidationStage::Release)->errors[0]->path?->toString())->toBe('ext.app.review_by')
        ->and($validator->validateWith(new NoteValidator, [...$input, 'ext' => ['app' => ['review_by' => '2026-10-01']]], ValidationStage::Release)->passed())->toBeTrue();
});
