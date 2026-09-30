<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\FieldTypes;

use Cbox\Cms\Contracts\FieldTypes\BooleanShape;
use Cbox\Cms\Contracts\FieldTypes\DateShape;
use Cbox\Cms\Contracts\FieldTypes\DatetimeShape;
use Cbox\Cms\Contracts\FieldTypes\DecimalShape;
use Cbox\Cms\Contracts\FieldTypes\FieldBase;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\FieldTypes\IntegerShape;
use Cbox\Cms\Contracts\FieldTypes\InvalidFieldShape;
use Cbox\Cms\Contracts\FieldTypes\LongTextShape;
use Cbox\Cms\Contracts\FieldTypes\SelectChoice;
use Cbox\Cms\Contracts\FieldTypes\SelectShape;
use Cbox\Cms\Contracts\FieldTypes\TextFormat as ShapeFormat;
use Cbox\Cms\Contracts\FieldTypes\TextShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\DocumentValues;
use Cbox\Cms\Generators\Schema\Boundary\OptionsSchemas;
use Cbox\Cms\Generators\Schema\Boundary\ReadProblems;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDate;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDatetime;
use Cbox\Cms\Generators\Schema\Domain\DecimalBound;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ShapedOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\ShapeOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use stdClass;

/*
 * The parts that turn the options of an addon's field type into what the generators write
 * (PRD 11.12, 13.1): the values read as FieldTypeOptions and checked against the options schema,
 * the shape made into the options of its core field type, and those options held under the field
 * type's own name.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * The values of a field whose `options` is the decoded JSON, recording into the problems.
 */
function optionsField(string $json, ReadProblems $problems): DocumentValues
{
    $field = json_decode('{"options": '.$json.'}', false, 512, JSON_THROW_ON_ERROR);
    assert($field instanceof stdClass);

    return new DocumentValues($field, new SourceLocation('schema/review.yaml', '/fields/0'), $problems, static fn (): ?array => null);
}

/**
 * A schema file in a scratch directory with the contents.
 */
function optionsSchemaFile(string $contents): string
{
    $path = SchemaFixtures::scratch().'/options.json';
    SchemaFixtures::write($path, $contents);

    return $path;
}

/**
 * @param  list<GenerationProblem>  $problems
 * @return list<string>
 */
function describedProblems(array $problems): array
{
    return array_map(static fn (GenerationProblem $problem): string => $problem->describe(), $problems);
}

it('reads the options as FieldTypeOptions: scalars, null, nested objects and lists of them', function (): void {
    $problems = new ReadProblems;
    $options = optionsField('{"max": 7, "ratio": 1.5, "half": false, "none": null, "label": "Stars", "tags": ["a", null, 2], "style": {"colour": "gold", "grades": [{"value": "good"}]}}', $problems)
        ->fieldTypeOptions('options', 'acme:stars', optionsSchemaFile('{}'));

    expect($problems->all())->toBe([])
        ->and($options)->toEqual(new FieldTypeOptions([
            'max' => 7,
            'ratio' => 1.5,
            'half' => false,
            'none' => null,
            'label' => 'Stars',
            'tags' => ['a', null, 2],
            'style' => new FieldTypeOptions(['colour' => 'gold', 'grades' => [new FieldTypeOptions(['value' => 'good'])]]),
        ]));
});

it('reads a field without options as the empty options, checked as {}', function (): void {
    $problems = new ReadProblems;
    $field = new DocumentValues(new stdClass, new SourceLocation('schema/review.yaml', '/fields/0'), $problems, static fn (): ?array => null);

    expect($field->fieldTypeOptions('options', 'acme:stars', optionsSchemaFile('{"type": "object"}')))->toEqual(new FieldTypeOptions)
        ->and($field->fieldTypeOptions('options', 'acme:grade', optionsSchemaFile('{"required": ["grades"]}')))->toBeNull()
        ->and(describedProblems($problems->all()))->toBe(['[generate_schema_invalid] schema/review.yaml, /fields/0/options: The required properties (grades) are missing (the options schema of the field type acme:grade)']);
});

it('refuses a list inside a list, at the inner list in the order of the file, and every violation of the schema at its pointer', function (): void {
    $problems = new ReadProblems;

    expect(optionsField('{"rows": [["a"], "b", ["c"]], "deep": {"grid": [[1]]}}', $problems)->fieldTypeOptions('options', 'acme:grid', optionsSchemaFile('{}')))->toBeNull()
        ->and(describedProblems($problems->all()))->toBe([
            '[generate_schema_invalid] schema/review.yaml, /fields/0/options/rows/0: a list inside a list, which the options of a field type do not hold. Use a list of objects instead.',
            '[generate_schema_invalid] schema/review.yaml, /fields/0/options/rows/2: a list inside a list, which the options of a field type do not hold. Use a list of objects instead.',
            '[generate_schema_invalid] schema/review.yaml, /fields/0/options/deep/grid/0: a list inside a list, which the options of a field type do not hold. Use a list of objects instead.',
        ]);

    $violations = new ReadProblems;
    $schema = optionsSchemaFile('{"properties": {"max": {"maximum": 10}, "min": {"minimum": 1}}}');

    expect(optionsField('{"max": 11, "min": 0}', $violations)->fieldTypeOptions('options', 'acme:stars', $schema))->toBeNull()
        ->and(describedProblems($violations->all()))->toBe([
            '[generate_schema_invalid] schema/review.yaml, /fields/0/options/max: Number must be lower than or equal to 10 (the options schema of the field type acme:stars)',
            '[generate_schema_invalid] schema/review.yaml, /fields/0/options/min: Number must be greater than or equal to 1 (the options schema of the field type acme:stars)',
        ]);
});

it('records a reason the field type gives against the options as generate_schema_invalid', function (): void {
    $problems = new ReadProblems;
    optionsField('{}', $problems)->invalid('options', 'A select shape has 1 to 500 choices, got 0.');

    expect(describedProblems($problems->all()))->toBe(['[generate_schema_invalid] schema/review.yaml, /fields/0/options: A select shape has 1 to 500 choices, got 0.']);
});

it('takes an options schema only as an absolute path to a readable JSON object', function (): void {
    $schemas = new OptionsSchemas;
    $refused = static function (string $path) use ($schemas): string {
        try {
            $schemas->load('acme:stars', $path);
        } catch (GenerationFailed $failed) {
            expect($failed->codes())->toBe([GenerateErrorCode::InvalidConfig]);

            return $failed->problems[0]->message;
        }

        return 'loaded';
    };

    expect($schemas->load('acme:stars', optionsSchemaFile('{"type": "object"}')))->toEqual((object) ['type' => 'object'])
        ->and($refused('C:\schemas\stars.json'))->toBe('The options schema C:\schemas\stars.json of the field type acme:stars does not exist or cannot be read. The addon that contributes the field type names it in FieldTypeContribution::optionsSchema().')
        ->and($refused('schemas/stars.json'))->toStartWith('The options schema schemas/stars.json of the field type acme:stars is not an absolute path. Build it from __DIR__.')
        ->and($refused('http://example.test/stars.json'))->toContain('is not an absolute path')
        ->and($refused(optionsSchemaFile('{"type": ')))->toContain('is not valid JSON: ')
        ->and($refused(optionsSchemaFile('"object"')))->toContain('is not a JSON object.');
});

it('makes the options of the core field type of each shape', function (FieldShape $shape, object $options): void {
    expect(ShapeOptions::of($shape))->toEqual($options);
})->with([
    'text' => [new TextShape(2, 40, ShapeFormat::Email), new TextOptions(2, 40, TextFormat::Email)],
    'long text' => [new LongTextShape(null, 500), new LongTextOptions(null, 500)],
    'integer' => [new IntegerShape(1, 5, 'stars'), new IntegerOptions(1, 5, 'stars')],
    'decimal' => [new DecimalShape(6, 2, '-1.5', '99.99', 'kg'), new DecimalOptions(6, 2, new DecimalBound('-1.5'), new DecimalBound('99.99'), 'kg')],
    'decimal without bounds' => [new DecimalShape(6, 2), new DecimalOptions(6, 2, null, null, null)],
    'boolean' => [new BooleanShape, new BooleanOptions],
    'date' => [new DateShape('2026-01-01', '2026-12-31'), new DateOptions(new BlueprintDate('2026-01-01'), new BlueprintDate('2026-12-31'))],
    'date without bounds' => [new DateShape, new DateOptions(null, null)],
    'datetime' => [new DatetimeShape('2026-01-01T00:00:00Z', '2027-01-01T00:00:00+01:00'), new DatetimeOptions(new BlueprintDatetime('2026-01-01T00:00:00Z'), new BlueprintDatetime('2027-01-01T00:00:00+01:00'))],
    'datetime without bounds' => [new DatetimeShape, new DatetimeOptions(null, null)],
    'select' => [new SelectShape([new SelectChoice('good', 'Good'), new SelectChoice('poor', 'Poor')], true, 1, 2), new SelectOptions([new SelectOption(new Handle('good'), 'Good'), new SelectOption(new Handle('poor'), 'Poor')], true, 1, 2)],
]);

it('refuses a shape that is not one of the contracts\' shapes, and a value of the wrong form', function (): void {
    $foreign = new class implements FieldShape
    {
        public function base(): FieldBase
        {
            return FieldBase::Text;
        }
    };

    expect(static fn (): object => ShapeOptions::of($foreign))->toThrow(InvalidFieldShape::class, sprintf('The shape %s is not one of the shapes of Cbox\Cms\Contracts\FieldTypes', $foreign::class))
        ->and(static fn (): object => ShapeOptions::of(new DateShape('2026-02-30')))->toThrow(GenerationFailed::class)
        ->and(static fn (): object => ShapeOptions::of(new DatetimeShape(max: '2026-01-01')))->toThrow(GenerationFailed::class)
        ->and(static fn (): object => ShapeOptions::of(new SelectShape([new SelectChoice('Good', 'Good')])))->toThrow(GenerationFailed::class);
});

it('holds the options of the base under the field type\'s own name, and names the base\'s problems below the options', function (): void {
    $options = new ShapedOptions('acme:span', new IntegerOptions(5, 1, null));

    expect($options->typeName())->toBe('acme:span')
        ->and($options->base->typeName())->toBe('integer')
        ->and($options->nestedFields())->toBe([])
        ->and($options->describeColumn('"span"'))->toEqual(new ColumnShape('bigint', ['"span" >= 5', '"span" <= 1']))
        ->and($options->describeValue([]))->toEqual(new IntegerOptions(5, 1, null)->describeValue([]))
        ->and(describedProblems($options->problems(new SourceLocation('schema/review.yaml', '/fields/0'))))->toBe(describedProblems(new IntegerOptions(5, 1, null)->problems(new SourceLocation('schema/review.yaml', '/fields/0/options'))))
        ->and($options->problems(new SourceLocation('schema/review.yaml', '/fields/0'))[0]->code)->toBe(GenerateErrorCode::MinAboveMax);
});
