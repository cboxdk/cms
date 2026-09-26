<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Actions;

use Cbox\Cms\Generators\Generation\Actions\GenerateCode;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Tests\Generation\Fakes\FakeGeneratedOutput;
use Cbox\Cms\Generators\Tests\Schema\Fakes\FakeBlueprintSource;
use Cbox\Cms\Generators\Tests\SchemaFixtures;

/*
 * cms:generate's action called directly with its GenerationTarget, the fake blueprint source and
 * the fake output (GUARDRAILS 9). BlueprintSourceBehaviour and GeneratedOutputBehaviour hold the
 * fakes to YamlBlueprintSource and FilesystemGeneratedOutput.
 */

function generateCode(FakeBlueprintSource $blueprints, FakeGeneratedOutput $output): GenerateCode
{
    return new GenerateCode($blueprints, new GeneratorRunner([new PhpTypeHandleEnum, new TypeScriptTypeHandles]), $output);
}

it('reads the target\'s schema roots, generates from them and writes the code below the root', function (): void {
    $blueprints = new FakeBlueprintSource;
    $blueprints->put(SchemaFixtures::root(), 'page.yaml', SchemaFixtures::type(SchemaFixtures::root(), 'page', ['title' => 'text']));
    $output = new FakeGeneratedOutput;
    $output->put('/srv/app/app/Cms/Generated/Stale.php', "<?php\n");

    $report = generateCode($blueprints, $output)->generate(SchemaFixtures::target('/srv/app'));

    expect($blueprints->reads)->toEqual([[SchemaFixtures::root()]])
        ->and($report->written)->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts'])
        ->and($report->removed)->toBe(['app/Cms/Generated/Stale.php'])
        ->and($output->files('/srv/app'))->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts'])
        ->and($output->contents('/srv/app/app/Cms/Generated/TypeHandle.php'))->toContain("    case Page = 'page';")
        ->and(generateCode($blueprints, $output)->generate(SchemaFixtures::target('/srv/app'))->changed())->toBeFalse();
});

it('applies an extension of another root to the type it extends', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text']);
    $blueprints = new FakeBlueprintSource;
    $blueprints->put($acme, 'product.yaml', $product);
    $blueprints->put($app, 'shop/product.yaml', SchemaFixtures::extension($app, 'shop/product.yaml', $product->typeId, ['tax_code' => 'text']));
    $output = new FakeGeneratedOutput;

    generateCode($blueprints, $output)->generate(SchemaFixtures::target('/srv/app', [$app, $acme]));

    expect($output->contents('/srv/app/app/Cms/Generated/TypeHandle.php'))->toContain("            self::Product => [\n                'app' => [\n                    'tax_code' => 'text',\n")
        ->and($output->contents('/srv/app/resources/js/cms/generated/index.ts'))->toContain("    ext: {\n      app: {\n        tax_code: 'text';\n");
});

it('generates the enum without cases and never from a root without blueprints', function (): void {
    $blueprints = new FakeBlueprintSource;
    $blueprints->root(SchemaFixtures::root());
    $output = new FakeGeneratedOutput;

    generateCode($blueprints, $output)->generate(SchemaFixtures::target('/srv/app'));

    expect($output->contents('/srv/app/app/Cms/Generated/TypeHandle.php'))->not->toContain('case ')
        ->and($output->contents('/srv/app/app/Cms/Generated/TypeHandle.php'))->toContain('        return [];')
        ->and($output->contents('/srv/app/resources/js/cms/generated/index.ts'))->toContain('export type TypeHandle = never;');
});

it('writes nothing when a schema root is missing', function (): void {
    $output = new FakeGeneratedOutput;

    expect(static fn (): WriteReport => generateCode(new FakeBlueprintSource, $output)->generate(SchemaFixtures::target('/srv/app')))
        ->toThrow(GenerationFailed::class, '[generate_schema_missing] The schema root /srv/app/schema of app does not exist')
        ->and($output->files('/srv/app'))->toBe([]);
});

it('passes on the problems of a blueprint file the source refuses, and writes nothing', function (): void {
    $blueprints = new FakeBlueprintSource;
    $blueprints->refuse(SchemaFixtures::root(), 'page.yaml', [new GenerationProblem(GenerateErrorCode::SchemaInvalid, 'schema/page.yaml, /handle: The data must match the pattern.')]);
    $output = new FakeGeneratedOutput;

    expect(static fn (): WriteReport => generateCode($blueprints, $output)->generate(SchemaFixtures::target('/srv/app')))
        ->toThrow(GenerationFailed::class, '[generate_schema_invalid] schema/page.yaml, /handle')
        ->and($output->files('/srv/app'))->toBe([]);
});

it('refuses the same type handle from two owners with generate_handle_collision, and writes nothing', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $blueprints = new FakeBlueprintSource;
    $blueprints->put($app, 'product.yaml', SchemaFixtures::type($app, 'product', ['title' => 'text']));
    $blueprints->put($acme, 'product.yaml', SchemaFixtures::type($acme, 'product', ['title' => 'text']));
    $output = new FakeGeneratedOutput;

    expect(static fn (): WriteReport => generateCode($blueprints, $output)->generate(SchemaFixtures::target('/srv/app', [$app, $acme])))
        ->toThrow(GenerationFailed::class, '[generate_handle_collision] The type "product" of app (schema/product.yaml) and the type "product" of acme')
        ->and($output->files('/srv/app'))->toBe([]);
});

it('passes on an output that cannot be written', function (): void {
    $blueprints = new FakeBlueprintSource;
    $blueprints->put(SchemaFixtures::root(), 'page.yaml', SchemaFixtures::type(SchemaFixtures::root(), 'page', ['title' => 'text']));
    $output = new FakeGeneratedOutput;
    $output->block('/srv/app/resources/js/cms/generated/index.ts');

    expect(static fn (): WriteReport => generateCode($blueprints, $output)->generate(SchemaFixtures::target('/srv/app')))
        ->toThrow(GenerationFailed::class, '[generate_output_unwritable] The generated file /srv/app/resources/js/cms/generated/index.ts could not be written');
});
