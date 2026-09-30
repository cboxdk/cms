<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\FieldTypes;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\FieldTypes\FieldBase;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaEntry;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Generation\Boundary\TypeScriptRuntime;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeQueries;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeValidators;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptContracts;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeTableMigrations;
use Cbox\Cms\Generators\Protocol\Boundary\KernelContracts;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintDocumentReader;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\RegisteredFieldTypes;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintRules;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Tests\FieldTypes\Fixtures\AcmeFieldTypes;
use Cbox\Cms\Generators\Tests\FieldTypes\Fixtures\GradeFieldType;
use Cbox\Cms\Generators\Tests\FieldTypes\Fixtures\SchemaAtFieldTypes;
use Cbox\Cms\Generators\Tests\FieldTypes\Fixtures\StarsFieldType;
use Cbox\Cms\Generators\Tests\Migrations\Fakes\FakeSchemaLocks;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Phpstan;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Assert;
use stdClass;
use Symfony\Component\Process\Process;

/*
 * Addons' field types in every generator (PRD 11.12, 13.1, 13.3): cms:generate registers the field
 * types of each addon that schema.php lists, through the FieldTypeContributor its manifest names,
 * only in the addon's namespace. A field of such a type is checked against the type's options
 * schema, and every generator writes it as the core field type its shape takes the form of, while
 * the field keeps the addon's type name. A `<namespace>:<handle>` of a namespace no addon
 * registers is still generate_unknown_field_type.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * The schema.php entry of the fixture addon acme/cms-ratings.
 */
function acmeEntry(?string $contributor = AcmeFieldTypes::class): SchemaEntry
{
    return new SchemaEntry(
        new AddonNamespace('acme'),
        AcmeFieldTypes::PACKAGE,
        [new ContributedFieldType(GradeFieldType::NAME), new ContributedFieldType(StarsFieldType::NAME)],
        [],
        [],
        $contributor,
    );
}

/**
 * The field types cms:generate registers for a compiled registry with the given schema entries.
 *
 * @param  list<SchemaEntry>  $entries
 * @param  (Closure(string): mixed)|null  $make  makes a contributor; `new` by default
 */
function addonRegistry(array $entries, ?Closure $make = null): FieldTypeRegistry
{
    return new RegisteredFieldTypes(
        static fn (): CompiledRegistry => new CompiledRegistry([], [], schema: $entries),
        $make ?? static fn (string $class): object => new $class,
    )->registry();
}

/**
 * The configuration problem of registering the field types of the entries.
 *
 * @param  Closure(): CompiledRegistry  $registry
 * @param  (Closure(string): mixed)|null  $make  makes a contributor; `new` by default
 */
function addonRegistryFailure(Closure $registry, ?Closure $make = null): GenerationFailed
{
    try {
        new RegisteredFieldTypes($registry, $make ?? static fn (string $class): object => new $class)->registry();
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The field types were registered.');
}

/**
 * A type file of the app with the given fields, as YAML list items.
 */
function reviewBlueprint(string $fields): string
{
    return <<<YAML
        blueprint: 1
        kind: type
        type_id: 0192a3b4-c5d6-7e8f-9a0b-000000000064
        handle: review
        label: Review
        description: A review with the fixture addon's field types.
        version: 1
        capabilities:
          history: full
          stages: none
          localization: none
        fields:
        {$fields}
        YAML;
}

/** The fields of the review: a filterable and sortable acme:stars, a required acme:grade, and acme:stars in a group. */
const REVIEW_FIELDS = <<<'YAML'
      - handle: rating
        label: Rating
        description: The stars of the review.
        type: acme:stars
        classification: public
        filterable: true
        sortable: true
        options:
          max: 7
      - handle: grade
        label: Grade
        description: The grade of the review.
        type: acme:grade
        classification: internal
        required: true
        options:
          grades:
            - value: good
              label: Good
            - value: poor
              label: Poor
      - handle: details
        label: Details
        description: More about the review.
        type: group
        classification: public
        fields:
          - handle: stars
            label: Stars
            description: The stars of the details.
            type: acme:stars
    YAML;

/**
 * The compiled schema of the review file in a scratch root, read with the field types.
 */
function reviewSchema(string $directory, FieldTypeRegistry $registry, string $fields = REVIEW_FIELDS): CompiledSchema
{
    SchemaFixtures::write($directory.'/schema/review.yaml', reviewBlueprint($fields));
    $source = new YamlBlueprintSource(new BlueprintSchemaFile, new BlueprintDocumentReader($registry), new BlueprintRules);

    return SchemaFixtures::compiled($source->read([SchemaFixtures::root(base: $directory)]));
}

/**
 * The failure of reading the review with the fields.
 */
function reviewFailure(FieldTypeRegistry $registry, string $fields): GenerationFailed
{
    try {
        reviewSchema(SchemaFixtures::scratch(), $registry, $fields);
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The reader accepted the review.');
}

/**
 * Every generator of the type chain, as the generators' service provider wires them for
 * cms:generate, with a schema lock store that has no lock yet.
 *
 * @return list<Generator>
 */
function everyGenerator(): array
{
    return [
        new PhpRecordDtos,
        new PhpTypeHandleEnum,
        new PhpRecords,
        new PhpTypeCatalog(ServiceProvider::class),
        new PhpTypeQueries,
        new PhpTypeValidators,
        new TypeScriptTypeHandles,
        new TypeScriptContracts(new TypeScriptRuntime()->source(...), new KernelContracts()->read(...)),
        new TypeTableMigrations(new FakeSchemaLocks),
    ];
}

it('generates a field of an addon field type in every generator, as the core field type of its shape', function (): void {
    $directory = SchemaFixtures::scratch();
    $schema = reviewSchema($directory, addonRegistry([acmeEntry()]));
    $fields = [];

    foreach ($schema->types[0]->fields as $field) {
        $fields[$field->handle->value] = $field;
    }

    $rating = $fields['rating'];
    $nested = $fields['details']->fields[0];

    // The descriptor: the addon's type name, and everything else of its base.
    expect([$rating->type, $rating->base, $rating->column?->type, $rating->column?->checks, $rating->php->doc, $rating->typeScript->type])
        ->toBe(['acme:stars', 'integer', 'bigint', ['"rating" >= 1', '"rating" <= 7'], 'int<1, 7>', 'number'])
        ->and([$fields['grade']->type, $fields['grade']->base, $fields['grade']->column?->checks])->toBe(['acme:grade', 'select', ['"grade" IN (\'good\', \'poor\')']])
        ->and([$nested->type, $nested->base, $nested->column, $nested->php->doc])->toBe(['acme:stars', 'integer', null, 'int<1, 5>']);

    $expected = [
        PhpRecordDtos::class => [
            'app/Cms/Generated/Domain/Dto/AppReviewV1.php' => ['public int|Omitted|null $rating,', 'public string|Omitted $grade,'],
            'app/Cms/Generated/Domain/Dto/AppReviewV1Details.php' => ['public int|Omitted|null $stars,'],
        ],
        PhpTypeHandleEnum::class => ['app/Cms/Generated/TypeHandle.php' => ["'rating' => 'acme:stars',", "'grade' => 'acme:grade',"]],
        PhpRecords::class => [
            'app/Cms/Generated/Records/AppReview/AppReviewRecord.php' => ['public ?int $rating { get; }', 'public GradeChoice $grade { get; }'],
            'app/Cms/Generated/Records/AppReview/GradeChoice.php' => ["case Good = 'good';", "case Poor = 'poor';"],
        ],
        PhpTypeCatalog::class => ['app/Cms/Generated/GeneratedTypeCatalog.php' => ["fieldType: 'acme:stars',", 'base: FieldBase::Integer,', "fieldType: 'acme:grade',", 'base: FieldBase::Select,']],
        PhpTypeQueries::class => [
            'app/Cms/Generated/QueryBuilders/AppReview/AppReviewQuery.php' => ['public function whereRating(FilterOperator $operator, int ...$values): self'],
            'app/Cms/Generated/QueryBuilders/AppReview/AppReviewSortField.php' => ["case Rating = 'rating';"],
        ],
        PhpTypeValidators::class => ['app/Cms/Generated/Validators/AppReviewValidator.php' => [' *   rating: acme:stars, optional', ' *   grade: acme:grade, required', ' *   details.stars: acme:stars, optional']],
        TypeScriptTypeHandles::class => ['resources/js/cms/generated/index.ts' => ["rating: 'acme:stars';", "grade: 'acme:grade';"]],
        TypeScriptContracts::class => ['resources/js/cms/generated/records/AppReviewV1.ts' => ['rating?: number | null;', "export type AppReviewV1GradeChoice = 'good' | 'poor';", 'stars?: number | null;']],
        TypeTableMigrations::class => ['database/migrations/cms/app__review_0001_create.php' => ['"rating" bigint', 'check ("rating" <= 7)', '"grade" text not null', 'create index "app__review__rating"']],
    ];
    $files = [];

    foreach (everyGenerator() as $generator) {
        $written = [];

        foreach ($generator->generate($schema, SchemaFixtures::target($directory)) as $file) {
            $written[$file->path] = $file->contents;
            $files[] = $file;
        }

        foreach ($expected[$generator::class] as $path => $lines) {
            expect($written)->toHaveKey($path);

            foreach ($lines as $line) {
                expect($written[$path])->toContain($line);
            }
        }
    }

    expect(array_keys($expected))->toBe(array_map(static fn (Generator $generator): string => $generator::class, everyGenerator()));

    // The generated PHP passes Pint and PHPStan level 10 as it is, and the catalog gives the kernel
    // each field's base.
    foreach ($files as $file) {
        SchemaFixtures::write($directory.'/'.$file->path, $file->contents);
    }

    $generated = $directory.'/app/Cms/Generated';
    $pint = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/pint.php', '--test', '--config='.Phpstan::root().'/pint.json', $generated], Phpstan::root());
    $pint->run();
    $analysis = Phpstan::analyse($generated);

    expect($pint->getExitCode())->toBe(0, $pint->getOutput())
        ->and($analysis->identifiers)->toBe([])
        ->and($analysis->exitCode)->toBe(0);

    $namespace = 'Cbox\Cms\Generators\Tests\FieldTypes\Loaded'.bin2hex(random_bytes(4));
    $catalog = array_first(array_filter($files, static fn (GeneratedFile $file): bool => $file->path === 'app/Cms/Generated/GeneratedTypeCatalog.php'));
    Assert::assertInstanceOf(GeneratedFile::class, $catalog);
    SchemaFixtures::write($directory.'/catalog.php', str_replace('namespace App\Cms\Generated;', 'namespace '.$namespace.';', $catalog->contents));
    require $directory.'/catalog.php';
    $class = $namespace.'\GeneratedTypeCatalog';
    $loaded = new $class;
    Assert::assertInstanceOf(TypeCatalog::class, $loaded);
    $definitions = [];

    foreach ($loaded->all()[0]->fields as $definition) {
        $definitions[$definition->handle->value] = $definition;
    }

    expect([$definitions['rating']->fieldType, $definitions['rating']->base, $definitions['rating']->valueType()])->toBe(['acme:stars', FieldBase::Integer, 'integer'])
        ->and($definitions['grade']->valueType())->toBe('select')
        ->and(array_map(static fn (FieldDefinition $inner): ?FieldBase => $inner->base, $definitions['details']->fields))->toBe([FieldBase::Integer]);
});

it('refuses <other>:<handle> of a namespace no addon registers with generate_unknown_field_type', function (): void {
    $failed = reviewFailure(addonRegistry([acmeEntry()]), <<<'YAML'
          - handle: colour
            label: Colour
            description: A colour of an addon that is not installed.
            type: other:colour
            classification: public
        YAML);

    expect($failed->codes())->toBe([GenerateErrorCode::UnknownFieldType])
        ->and($failed->problems[0]->message)->toBe('schema/review.yaml, /fields/0/type: no field type contributor registers the field type other:colour, so it cannot be read. The registered field types are acme:grade, acme:stars, boolean, date, datetime, decimal, group, integer, long_text, rich_text, select, text.');
});

it('checks the options against the field type\'s options schema, at each value\'s JSON pointer', function (): void {
    $failed = reviewFailure(addonRegistry([acmeEntry()]), <<<'YAML'
          - handle: rating
            label: Rating
            description: The stars of the review.
            type: acme:stars
            classification: public
            options:
              max: 11
          - handle: grade
            label: Grade
            description: The grade of the review.
            type: acme:grade
            classification: public
        YAML);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid, GenerateErrorCode::SchemaInvalid])
        ->and($failed->problems[0]->message)->toStartWith('schema/review.yaml, /fields/0/options/max: Number must be lower than or equal to 10')
        ->and($failed->problems[0]->message)->toEndWith('(the options schema of the field type acme:stars)')
        ->and($failed->problems[1]->message)->toStartWith('schema/review.yaml, /fields/1/options: The required properties (grades) are missing');
});

it('refuses options the field type cannot make a shape of, and a shape value of the wrong form, at the options', function (): void {
    $failed = reviewFailure(addonRegistry([acmeEntry()]), <<<'YAML'
          - handle: grade
            label: Grade
            description: The grade of the review.
            type: acme:grade
            classification: public
            options:
              grades:
                - value: good
                  label: Good
                - value: good
                  label: Also good
          - handle: tier
            label: Tier
            description: The tier of the review.
            type: acme:grade
            classification: public
            options:
              grades:
                - value: Top Tier
                  label: Top
        YAML);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid, GenerateErrorCode::SchemaInvalid])
        ->and($failed->problems[0]->message)->toBe('schema/review.yaml, /fields/0/options: A select shape has each value once, got good, good.')
        ->and($failed->problems[1]->message)->toStartWith('schema/review.yaml, /fields/1/options: "Top Tier" is not a handle');
});

it('holds a field of an addon field type to what its base can do: a long text or several options are not queryable', function (): void {
    $schema = reviewSchema(SchemaFixtures::scratch(), addonRegistry([acmeEntry()]), <<<'YAML'
          - handle: rating
            label: Rating
            description: The stars of the review.
            type: acme:stars
            classification: public
            sortable: true
        YAML);
    $rating = $schema->types[0]->fields[0];
    $multiple = new FieldDescriptor($rating->column, $rating->handle, $rating->namespace, $rating->owner, 'acme:tags', $rating->label, $rating->description, $rating->required, $rating->classification, $rating->agents, true, false, $rating->encrypted, $rating->php->withNullable(true), $rating->typeScript, $rating->validation, $rating->choices, [], $rating->location, 'long_text');
    $changed = new CompiledSchema([new TypeDescriptor($schema->types[0]->typeId, $schema->types[0]->owner, $schema->types[0]->handle, $schema->types[0]->label, $schema->types[0]->description, $schema->types[0]->version, $schema->types[0]->capabilities, [], [$multiple], $schema->types[0]->location)]);

    try {
        new PhpTypeQueries()->generate($changed, SchemaFixtures::target());
        Assert::fail('The query builder was generated.');
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::FieldNotQueryable])
            ->and($failed->problems[0]->message)->toContain('but a field of the type acme:tags, in the form of a long_text, has no order the query builder can compare');
    }
});

it('reports every contributor it cannot use as generate_invalid_config', function (): void {
    $wrongNames = new SchemaEntry(new AddonNamespace('glossary'), 'acme/cms-glossary', [new ContributedFieldType('glossary:stars')], [], [], AcmeFieldTypes::class);
    $notContributor = new SchemaEntry(new AddonNamespace('shop'), 'acme/cms-shop', [new ContributedFieldType('shop:stars')], [], [], stdClass::class);
    $failed = addonRegistryFailure(static fn (): CompiledRegistry => new CompiledRegistry([], [], schema: [$wrongNames, $notContributor]));

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidConfig, GenerateErrorCode::InvalidConfig])
        ->and($failed->problems[0]->message)->toBe(sprintf('The field type contributor %s of addon "glossary" (acme/cms-glossary) returns the field types acme:stars, acme:grade, and the manifest lists glossary:stars. Return each field type the manifest lists, once, and run `cms:build` after changing the manifest.', AcmeFieldTypes::class))
        ->and($failed->problems[1]->message)->toBe('The field type contributor stdClass of addon "shop" (acme/cms-shop) does not implement Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor.');
});

it('reports an options schema it cannot use, and a registry it cannot read, as generate_invalid_config', function (): void {
    $directory = SchemaFixtures::scratch();
    SchemaFixtures::write($directory.'/list.json', '[1, 2]');
    SchemaFixtures::write($directory.'/broken.json', '{"type": "object", "properties": {"max": {"$ref": "#/$defs/missing"}}}');
    $registry = static fn (): CompiledRegistry => new CompiledRegistry([], [], schema: [acmeEntry(SchemaAtFieldTypes::class)]);
    $unusable = static fn (string $schema): GenerationFailed => addonRegistryFailure($registry, static fn (string $class): object => new SchemaAtFieldTypes($schema));

    expect($unusable($directory.'/missing.json')->problems[0]->message)->toBe(sprintf('The options schema %s/missing.json of the field type acme:stars does not exist or cannot be read. The addon that contributes the field type names it in FieldTypeContribution::optionsSchema().', $directory))
        ->and($unusable('stars.options.json')->problems[0]->message)->toStartWith('The options schema stars.options.json of the field type acme:stars is not an absolute path.')
        ->and($unusable($directory.'/list.json')->problems[0]->message)->toContain('list.json of the field type acme:stars is not a JSON object.')
        ->and(addonRegistryFailure(static fn (): CompiledRegistry => throw RegistryCacheMissing::at('/app/bootstrap/cache/cms/schema.php'))->problems[0]->message)
        ->toBe('The compiled registry, which lists the addons\' field types, cannot be read: [registry_cache_missing] The registry cache file /app/bootstrap/cache/cms/schema.php does not exist. Run php artisan cms:build, which composer dump-autoload also runs, and make the deploy run it.');
});

it('reports an options schema whose reference does not resolve when a field reaches it, as generate_invalid_config', function (): void {
    $directory = SchemaFixtures::scratch();
    SchemaFixtures::write($directory.'/broken.json', '{"type": "object", "properties": {"max": {"$ref": "#/$defs/missing"}}}');
    $registry = addonRegistry([acmeEntry(SchemaAtFieldTypes::class)], static fn (string $class): object => new SchemaAtFieldTypes($directory.'/broken.json'));
    $failed = reviewFailure($registry, <<<'YAML'
          - handle: rating
            label: Rating
            description: The stars of the review.
            type: acme:stars
            classification: public
            options:
              max: 3
        YAML);

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidConfig])
        ->and($failed->problems[0]->message)->toStartWith(sprintf('The options schema %s/broken.json of the field type acme:stars is not a JSON Schema the validator can use: ', $directory));
});

it('registers the addons\' field types of the compiled registry for cms:generate, which exits 78 when a contributor cannot be used', function (): void {
    app()->instance(CompiledRegistry::class, new CompiledRegistry([], [], schema: [acmeEntry()]));
    app()->forgetInstance(FieldTypeRegistry::class);

    expect(app(FieldTypeRegistry::class)->find(StarsFieldType::NAME)?->name())->toBe(StarsFieldType::NAME)
        ->and(app(FieldTypeRegistry::class)->find('other:colour'))->toBeNull();

    app()->instance(CompiledRegistry::class, new CompiledRegistry([], [], schema: [acmeEntry(stdClass::class)]));
    app()->forgetInstance(FieldTypeRegistry::class);
    $artisan = app(Kernel::class);

    expect($artisan->call('cms:generate'))->toBe(GenerateCommand::EXIT_INVALID_CONFIG)
        ->and($artisan->output())->toContain('[generate_invalid_config] The field type contributor stdClass of addon "acme" (acme/cms-ratings) does not implement Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor.');
});

it('reports a contributor the container cannot make as generate_invalid_config', function (): void {
    $failed = addonRegistryFailure(
        static fn (): CompiledRegistry => new CompiledRegistry([], [], schema: [acmeEntry('Acme\Ratings\MissingFieldTypes')]),
        static fn (string $class): never => throw new BindingResolutionException(sprintf('Target class [%s] does not exist.', $class)),
    );

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidConfig])
        ->and($failed->problems[0]->message)->toBe('The field type contributor Acme\Ratings\MissingFieldTypes of addon "acme" (acme/cms-ratings) cannot be made: Target class [Acme\Ratings\MissingFieldTypes] does not exist.');
});

it('imports FieldBase into the catalog when only a nested field is of an addon field type', function (): void {
    $schema = reviewSchema(SchemaFixtures::scratch(), addonRegistry([acmeEntry()]), <<<'YAML'
          - handle: details
            label: Details
            description: More about the review.
            type: group
            classification: public
            fields:
              - handle: stars
                label: Stars
                description: The stars of the details.
                type: acme:stars
        YAML);
    $catalog = new PhpTypeCatalog(ServiceProvider::class)->generate($schema, SchemaFixtures::target())[0];

    expect($catalog->path)->toBe('app/Cms/Generated/GeneratedTypeCatalog.php')
        ->and($catalog->contents)->toContain("use Cbox\\Cms\\Contracts\\FieldTypes\\FieldBase;\n")
        ->and($catalog->contents)->toContain('base: FieldBase::Integer,');
});

it('names the addon field type and its base when a generator does not map the base', function (): void {
    $schema = reviewSchema(SchemaFixtures::scratch(), addonRegistry([acmeEntry()]), <<<'YAML'
          - handle: rating
            label: Rating
            description: The stars of the review.
            type: acme:stars
            classification: public
        YAML);
    $type = $schema->types[0];
    $rating = $type->fields[0];
    $unmapped = new FieldDescriptor($rating->column, $rating->handle, $rating->namespace, $rating->owner, $rating->type, $rating->label, $rating->description, $rating->required, $rating->classification, $rating->agents, $rating->filterable, $rating->sortable, $rating->encrypted, $rating->php, $rating->typeScript, $rating->validation, $rating->choices, [], $rating->location, 'relation');
    $changed = new CompiledSchema([new TypeDescriptor($type->typeId, $type->owner, $type->handle, $type->label, $type->description, $type->version, $type->capabilities, [], [$unmapped], $type->location)]);

    foreach ([new PhpTypeHandleEnum, new PhpRecordDtos] as $generator) {
        try {
            $generator->generate($changed, SchemaFixtures::target());
            Assert::fail($generator::class.' generated the field.');
        } catch (GenerationFailed $failed) {
            expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
                ->and($failed->problems[0]->message)->toContain('has no mapping for the field type "relation", the base of the field type "acme:stars", of schema/review.yaml, /fields/0.');
        }
    }
});
