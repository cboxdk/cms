<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Queries;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeQueries;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * The typed query builder of each type (PRD 8.8, 11.12): the comprehensive example generates
 * exactly the committed golden builder, which PHPStan level 10 checks with the rest of the
 * repository; a builder generated into an application passes Pint, Rector and PHPStan unchanged;
 * and a field that cannot be compared, or two methods PHP would take for one, are refused.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * The compiled schema of one app type, app:thing, with the fields: handle to the field's other
 * keys, each public.
 *
 * @param  array<array-key, mixed>  $fields
 */
function queryTypeSchema(array $fields): CompiledSchema
{
    $base = SchemaFixtures::scratch();
    SchemaFixtures::write($base.'/schema/thing.yaml', Yaml::dump([
        'blueprint' => 1,
        'kind' => 'type',
        'type_id' => '0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d21',
        'handle' => 'thing',
        'label' => 'Thing',
        'version' => 1,
        'capabilities' => ['history' => 'full', 'stages' => 'draft-release', 'localization' => 'none'],
        'fields' => array_map(
            static fn (int|string $handle, mixed $field): array => ['handle' => (string) $handle, 'label' => ucfirst(str_replace('_', ' ', (string) $handle)), 'description' => 'The '.str_replace('_', ' ', (string) $handle).'.', 'classification' => 'public', ...(is_array($field) ? $field : [])],
            array_keys($fields),
            array_values($fields),
        ),
    ], 8, 2));

    return DescriptorCompiler::compile(SchemaResolver::resolve(ComprehensiveExample::source()->read([new SchemaRoot(new Owner('app'), $base, 'schema')])));
}

/**
 * The problems PhpTypeQueries reports for the schema.
 *
 * @return list<GenerationProblem>
 */
function queryProblems(CompiledSchema $schema): array
{
    try {
        new PhpTypeQueries()->generate($schema, SchemaFixtures::target());
    } catch (GenerationFailed $failed) {
        return $failed->problems;
    }

    return [];
}

/**
 * The schema with every field declared filterable or sortable, as a descriptor the blueprint
 * schema would refuse for rich text and groups, which the generator must refuse too.
 */
function flagged(CompiledSchema $schema, string $flag): CompiledSchema
{
    return new CompiledSchema(array_map(
        static fn (TypeDescriptor $type): TypeDescriptor => new TypeDescriptor(
            $type->typeId,
            $type->owner,
            $type->handle,
            $type->label,
            $type->description,
            $type->version,
            $type->capabilities,
            $type->extensions,
            array_map(static fn (FieldDescriptor $field): FieldDescriptor => new FieldDescriptor(
                $field->column,
                $field->handle,
                $field->namespace,
                $field->owner,
                $field->type,
                $field->label,
                $field->description,
                $field->required,
                $field->classification,
                $field->agents,
                $flag === 'filterable',
                $flag === 'sortable',
                $field->encrypted,
                $field->php,
                $field->typeScript,
                $field->validation,
                $field->choices,
                $field->fields,
                $field->location,
            ), $type->fields),
            $type->location,
        ),
        $schema->types,
    ));
}

it('generates the committed golden query builder of the comprehensive example', function (): void {
    $generated = array_filter(ComprehensiveExample::php(), static fn (string $path): bool => str_contains($path, '/QueryBuilders/'), ARRAY_FILTER_USE_KEY);
    $golden = array_filter(ComprehensiveExample::goldenPhp(), static fn (string $path): bool => str_contains($path, '/QueryBuilders/'), ARRAY_FILTER_USE_KEY);

    expect(array_keys($generated))->toBe([
        'Generated/QueryBuilders/ShopProduct/ShopProductFilterField.php',
        'Generated/QueryBuilders/ShopProduct/ShopProductQuery.php',
        'Generated/QueryBuilders/ShopProduct/ShopProductSortField.php',
    ])
        ->and($generated)->toBe($golden);
});

it('lists the owner\'s filterable and sortable fields in the enums, and no extension field', function (): void {
    $files = ComprehensiveExample::php();

    $filter = $files['Generated/QueryBuilders/ShopProduct/ShopProductFilterField.php'];
    $sort = $files['Generated/QueryBuilders/ShopProduct/ShopProductSortField.php'];
    $query = $files['Generated/QueryBuilders/ShopProduct/ShopProductQuery.php'];

    expect($filter)->toContain("case Colour = 'colour';", "case Discontinued = 'discontinued';", "case Name = 'name';", "case Stock = 'stock';")
        ->and(str_contains($filter, 'tax_code'))->toBeFalse()
        ->and($sort)->toContain("case LaunchDate = 'launch_date';", "case Price = 'price';")
        ->and(str_contains($sort, "'colour'"))->toBeFalse()
        ->and($query)->toContain(
            'public function whereColour(FilterOperator $operator, ColourChoice ...$values): self',
            'public function whereStock(FilterOperator $operator, int ...$values): self',
            '@return RecordPage<ShopProductRecord>',
        )
        ->and(str_contains($query, 'whereTaxCode'))->toBeFalse();
});

it('gives each comparable field type its typed filter', function (array $field, string $parameter, string $value): void {
    $files = new PhpTypeQueries()->generate(queryTypeSchema(['probe' => [...$field, 'filterable' => true]]), SchemaFixtures::target());
    $query = array_find($files, static fn ($file): bool => str_ends_with($file->path, 'AppThingQuery.php'))?->contents;

    expect($query)->toContain('public function whereProbe(FilterOperator $operator, '.$parameter.' ...$values): self')
        ->toContain('=> '.$value.', $values));');
})->with([
    'text' => [['type' => 'text'], 'string', 'new TextValue($value)'],
    'integer' => [['type' => 'integer'], 'int', 'new IntegerValue($value)'],
    'decimal' => [['type' => 'decimal', 'precision' => 8, 'scale' => 2], 'string', 'new DecimalValue($value)'],
    'boolean' => [['type' => 'boolean'], 'bool', 'new BooleanValue($value)'],
    'date' => [['type' => 'date'], 'DateTimeImmutable', "new DateValue(\$value->format('Y-m-d'))"],
    'date-time' => [['type' => 'datetime'], 'DateTimeImmutable', 'new DateTimeValue($value)'],
    'select' => [['type' => 'select', 'options' => [['value' => 'one', 'label' => 'One']]], 'ProbeChoice', 'new TextValue($value->value)'],
]);

it('refuses a filterable or sortable field that has no comparable order', function (array $field, string $flag): void {
    $problems = queryProblems(flagged(queryTypeSchema(['probe' => $field]), $flag));

    expect(array_map(static fn (GenerationProblem $problem): GenerateErrorCode => $problem->code, $problems))->toBe([GenerateErrorCode::FieldNotQueryable])
        ->and($problems[0]->message)->toContain('probe of app:thing is declared '.$flag);
})->with([
    'filterable rich text' => [['type' => 'rich_text'], 'filterable'],
    'sortable group' => [['type' => 'group', 'fields' => [['handle' => 'inner', 'label' => 'Inner', 'description' => 'The inner text.', 'type' => 'text']]], 'sortable'],
    'a select of several options' => [['type' => 'select', 'multiple' => true, 'options' => [['value' => 'one', 'label' => 'One']]], 'filterable'],
]);

it('refuses two filterable fields whose methods PHP takes for one', function (): void {
    $problems = queryProblems(queryTypeSchema([
        'a_b' => ['type' => 'text', 'filterable' => true],
        'ab' => ['type' => 'text', 'filterable' => true],
    ]));

    expect(array_map(static fn (GenerationProblem $problem): GenerateErrorCode => $problem->code, $problems))->toBe([GenerateErrorCode::NameCollision])
        ->and($problems[0]->message)->toContain('would get the method whereAb, which the field a_b has already');
});

it('generates builders that Pint, Rector and PHPStan accept unchanged in an application', function (): void {
    $directory = SchemaFixtures::scratch();
    $target = SchemaFixtures::target($directory);
    $schema = queryTypeSchema([
        'title' => ['type' => 'text', 'required' => true, 'filterable' => true, 'sortable' => true],
        'size' => ['type' => 'select', 'filterable' => true, 'options' => [['value' => 'small', 'label' => 'Small']]],
        'weight' => ['type' => 'decimal', 'precision' => 8, 'scale' => 2, 'filterable' => true, 'sortable' => true],
        'born_on' => ['type' => 'date', 'filterable' => true, 'sortable' => true],
        'seen_at' => ['type' => 'datetime', 'filterable' => true],
        'count' => ['type' => 'integer', 'sortable' => true],
        'flag' => ['type' => 'boolean', 'filterable' => true],
        'body' => ['type' => 'rich_text'],
    ]);

    foreach ([...new PhpRecords()->generate($schema, $target), ...new PhpTypeQueries()->generate($schema, $target)] as $file) {
        SchemaFixtures::write($directory.'/'.$file->path, $file->contents);
    }

    $generated = $directory.'/app/Cms/Generated';
    $pint = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/pint.php', '--test', '--config='.Phpstan::root().'/pint.json', $generated.'/QueryBuilders'], Phpstan::root());
    $pint->run();
    $rector = new Process([Phpstan::root().'/vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar', $generated.'/QueryBuilders'], Phpstan::root());
    $rector->run();
    $analysis = Phpstan::analyse($generated);

    expect(SchemaFixtures::files($generated.'/QueryBuilders'))->toBe(['AppThing/AppThingFilterField.php', 'AppThing/AppThingQuery.php', 'AppThing/AppThingSortField.php'])
        ->and($pint->getExitCode())->toBe(0, $pint->getOutput())
        ->and($rector->getExitCode())->toBe(0, $rector->getOutput())
        ->and($analysis->identifiers)->toBe([])
        ->and($analysis->exitCode)->toBe(0);
});
