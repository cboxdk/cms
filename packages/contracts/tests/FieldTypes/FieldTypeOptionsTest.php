<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\FieldTypes;

use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\FieldTypes\InvalidFieldTypeOptions;

/*
 * The options of a field of an addon's field type, as the field type reads them (PRD 13.1): each
 * value in the kind it asks for, null when absent or null, and InvalidFieldTypeOptions for a value
 * of another kind.
 */

function sampleOptions(): FieldTypeOptions
{
    return new FieldTypeOptions([
        'max' => 7,
        'label' => 'Stars',
        'half' => false,
        'ratio' => 1.5,
        'whole' => 3.0,
        'none' => null,
        'tags' => ['a', 'b'],
        'grades' => [new FieldTypeOptions(['value' => 'good']), new FieldTypeOptions(['value' => 'poor'])],
        'style' => new FieldTypeOptions(['colour' => 'gold']),
    ]);
}

it('reads each value in the kind asked for, and null for a key it lacks or that holds null', function (): void {
    $options = sampleOptions();

    expect($options->keys())->toBe(['grades', 'half', 'label', 'max', 'none', 'ratio', 'style', 'tags', 'whole'])
        ->and($options->has('none'))->toBeTrue()
        ->and($options->has('min'))->toBeFalse()
        ->and($options->integer('max'))->toBe(7)
        ->and($options->integer('whole'))->toBe(3)
        ->and($options->integer('min'))->toBeNull()
        ->and($options->integer('none'))->toBeNull()
        ->and($options->number('ratio'))->toBe(1.5)
        ->and($options->number('max'))->toBe(7)
        ->and($options->string('label'))->toBe('Stars')
        ->and($options->boolean('half'))->toBeFalse()
        ->and($options->strings('tags'))->toBe(['a', 'b'])
        ->and(array_map(static fn (FieldTypeOptions $grade): ?string => $grade->string('value'), $options->objects('grades') ?? []))->toBe(['good', 'poor'])
        ->and($options->object('style')?->string('colour'))->toBe('gold')
        ->and($options->objects('none'))->toBeNull()
        ->and(new FieldTypeOptions()->keys())->toBe([]);
});

it('refuses a value of another kind than the one asked for', function (string $method, string $key, string $message): void {
    expect(static fn (): mixed => sampleOptions()->{$method}($key))->toThrow(InvalidFieldTypeOptions::class, $message);
})->with([
    'a string as an integer' => ['integer', 'label', 'The option "label" is not an integer.'],
    'a fraction as an integer' => ['integer', 'ratio', 'The option "ratio" is not an integer.'],
    'an integer as a string' => ['string', 'max', 'The option "max" is not a string.'],
    'a string as a number' => ['number', 'label', 'The option "label" is not a number.'],
    'an integer as a boolean' => ['boolean', 'max', 'The option "max" is not a boolean.'],
    'a string as an object' => ['object', 'label', 'The option "label" is not an object.'],
    'a string as a list' => ['strings', 'label', 'The option "label" is not a list.'],
    'objects as strings' => ['strings', 'grades', 'The option "grades" is not a list of strings.'],
    'strings as objects' => ['objects', 'tags', 'The option "tags" is not a list of objects.'],
]);
