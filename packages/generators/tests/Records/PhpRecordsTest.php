<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Records;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Assert;

/*
 * The PHP records and the type catalog of the type chain (PRD 11.12): the comprehensive example
 * generates exactly the committed golden PHP, which PHPStan level 10, Rector and Pint check with
 * the rest of the repository, and the generators refuse a schema whose names would collide in PHP.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * The compiled schema of blueprint files written below a scratch directory: owner to file name to
 * YAML without the `blueprint: 1` line.
 *
 * @param  array<string, array<string, string>>  $owners
 */
function compiledYaml(array $owners): CompiledSchema
{
    $base = SchemaFixtures::scratch();
    $roots = [];

    foreach ($owners as $owner => $files) {
        foreach ($files as $name => $yaml) {
            SchemaFixtures::write($base.'/'.$owner.'/'.$name, "blueprint: 1\n".$yaml);
        }

        $roots[] = new SchemaRoot(new Owner($owner), $base, $owner);
    }

    return DescriptorCompiler::compile(SchemaResolver::resolve(ComprehensiveExample::source()->read($roots)));
}

/**
 * A type file of the app with the fields in YAML, each a list item.
 */
function typeYaml(string $handle, string $fields, string $typeId = '0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d21'): string
{
    return <<<YAML
        kind: type
        type_id: {$typeId}
        handle: {$handle}
        label: Thing
        version: 1
        capabilities:
          history: full
          stages: draft-release
          localization: none
        fields:
        {$fields}
        YAML;
}

/**
 * @return array<string, string> path to contents
 */
function recordFiles(CompiledSchema $schema): array
{
    $files = [];

    foreach ([...new PhpRecords()->generate($schema, SchemaFixtures::target()), ...new PhpTypeCatalog(ServiceProvider::class)->generate($schema, SchemaFixtures::target())] as $file) {
        Assert::assertInstanceOf(GeneratedFile::class, $file);
        $files[$file->path] = $file->contents;
    }

    return $files;
}

it('generates the committed golden PHP of the comprehensive example', function (): void {
    $generated = ComprehensiveExample::php();

    expect(array_keys($generated))->toBe(array_keys(ComprehensiveExample::goldenPhp()), 'The generated files differ from the golden files in '.ComprehensiveExample::DIRECTORY.'/'.ComprehensiveExample::PHP_DIRECTORY.'. Review the difference; when it is intended, write the new files there.');

    foreach (ComprehensiveExample::goldenPhp() as $path => $contents) {
        expect($generated[$path])->toBe($contents, $path.' differs from its golden file. Review the difference; when it is intended, write the new file there.');
    }
});

it('writes the owner\'s interface, the extender\'s interfaces and the composite record that implements them all', function (): void {
    $files = ComprehensiveExample::php();
    $directory = 'Generated/Records/ShopProduct/';

    expect($files[$directory.'ShopProduct.php'])->toContain('final readonly class ShopProduct implements ShopProductAppExtension, ShopProductRecord')
        ->and($files[$directory.'ShopProductRecord.php'])->toContain('interface ShopProductRecord')
        ->and($files[$directory.'ShopProductRecord.php'])->not->toContain('taxCode')
        ->and($files[$directory.'ShopProductAppExtension.php'])->toContain('public ShopProductAppExt $ext { get; }')
        ->and($files[$directory.'ShopProductAppExt.php'])->toContain('public ShopProductAppFields $app { get; }')
        ->and($files[$directory.'ShopProductAppFields.php'])->toContain('public ?string $taxCode,')
        ->and($files[$directory.'ColourChoice.php'])->toContain("case Green = 'green';")
        ->and($files['Generated/GeneratedTypesServiceProvider.php'])->toContain('$this->app->singleton(ShopProductRecordFactory::class, ShopProductFactory::class);')
        ->and($files['Generated/GeneratedTypeCatalog.php'])->toContain("new ExtensionVersion(new FieldNamespace('app'), 2)");
});

it('gives every extender its own interface and the composite record every one of them', function (): void {
    $owner = typeYaml('item', <<<'YAML'
          - handle: title
            label: Title
            description: D
            type: text
            classification: public
        YAML);
    $extension = static fn (string $handle): string => <<<YAML
        kind: extension
        extends: 0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d21
        version: 1
        fields:
          - handle: {$handle}
            label: Code
            description: D
            type: text
            classification: public
        YAML;

    $files = recordFiles(compiledYaml([
        'shop' => ['item.yaml' => $owner],
        'app' => ['item.yaml' => $extension('code')],
        'erp' => ['item.yaml' => $extension('code')],
    ]));
    $directory = 'app/Cms/Generated/Records/ShopItem/';

    expect($files[$directory.'ShopItem.php'])->toContain('final readonly class ShopItem implements ShopItemAppExtension, ShopItemErpExtension, ShopItemRecord')
        ->and($files[$directory.'ShopItemExt.php'])->toContain('final readonly class ShopItemExt implements ShopItemAppExt, ShopItemErpExt')
        ->and($files[$directory.'ShopItem.php'])->toContain("new ExtensionFields(new FieldNamespace('erp'), \$this->ext->erp->toFieldMap()),")
        ->and($files)->toHaveKeys([$directory.'ShopItemAppFields.php', $directory.'ShopItemErpFields.php', $directory.'ShopItemErpExtension.php']);
});

it('writes an empty catalog and binds only the catalog without types', function (): void {
    $files = recordFiles(new CompiledSchema([]));

    expect(array_keys($files))->toBe(['app/Cms/Generated/GeneratedTypeCatalog.php', 'app/Cms/Generated/GeneratedTypesServiceProvider.php'])
        ->and($files['app/Cms/Generated/GeneratedTypeCatalog.php'])->toContain('        $this->types = [];')
        ->and($files['app/Cms/Generated/GeneratedTypeCatalog.php'])->not->toContain('use Cbox\Cms\Contracts\Schema\ColumnDefinition;')
        ->and($files['app/Cms/Generated/GeneratedTypesServiceProvider.php'])->toContain("        \$this->app->singleton(TypeCatalog::class, GeneratedTypeCatalog::class);\n    }");
});

it('gives the same bytes whatever the order of the fields in the blueprint', function (): void {
    $fields = [
        "  - handle: beta\n    label: Beta\n    description: D\n    type: integer\n    classification: public\n",
        "  - handle: alpha\n    label: Alpha\n    description: D\n    type: select\n    classification: public\n    options:\n      - value: one\n        label: One\n",
    ];

    expect(recordFiles(compiledYaml(['app' => ['thing.yaml' => typeYaml('thing', implode('', $fields))]])))
        ->toBe(recordFiles(compiledYaml(['app' => ['thing.yaml' => typeYaml('thing', implode('', array_reverse($fields)))]])));
});

it('refuses names that collide or that PHP reserves', function (string $fields, string $message): void {
    $schema = compiledYaml(['app' => ['thing.yaml' => typeYaml('thing', $fields)]]);

    try {
        new GeneratorRunner([new PhpRecords])->run($schema, SchemaFixtures::target());
        Assert::fail('The records were generated.');
    } catch (GenerationFailed $failed) {
        expect($failed->problems[0]->code)->toBe(GenerateErrorCode::NameCollision)
            ->and($failed->getMessage())->toContain($message);
    }
})->with([
    'two properties' => [
        "  - handle: size_1\n    label: A\n    description: D\n    type: text\n    classification: public\n  - handle: size1\n    label: B\n    description: D\n    type: text\n    classification: public\n",
        'the field size_1 of app:thing would get the property $size1, which the field size1 has already',
    ],
    'two enums' => [
        "  - handle: size_1\n    label: A\n    description: D\n    type: select\n    classification: public\n    options:\n      - value: one\n        label: One\n  - handle: size1\n    label: B\n    description: D\n    type: select\n    classification: public\n    options:\n      - value: one\n        label: One\n",
        'would get the PHP class name Size1Choice, which the field size1 has already',
    ],
    'two options' => [
        "  - handle: size\n    label: A\n    description: D\n    type: select\n    classification: public\n    options:\n      - value: x_1\n        label: One\n      - value: x1\n        label: Two\n",
        'the field size of app:thing would get the enum case SizeChoice::X1, which the option x_1 has already',
    ],
    'an option PHP reserves' => [
        "  - handle: size\n    label: A\n    description: D\n    type: select\n    classification: public\n    options:\n      - value: class\n        label: Class\n",
        'would get the enum case SizeChoice::Class, which PHP has already',
    ],
    'a property PHP reserves' => [
        "  - handle: this\n    label: This\n    description: D\n    type: text\n    classification: public\n",
        'would get the property $this, which PHP has already',
    ],
]);

it('refuses a field whose class would get the name of its type', function (): void {
    $schema = compiledYaml(['app' => ['thing.yaml' => typeYaml('thing_choice', "  - handle: app_thing\n    label: A\n    description: D\n    type: select\n    classification: public\n    options:\n      - value: one\n        label: One\n")]]);

    expect(fn (): mixed => new GeneratorRunner([new PhpRecords])->run($schema, SchemaFixtures::target()))
        ->toThrow(GenerationFailed::class, 'the field app_thing of app:thing_choice would get the PHP class name AppThingChoice, which the type has already');
});
