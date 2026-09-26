<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Actions;

use Cbox\Cms\Generators\Generation\Actions\GenerateCode;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Tests\Generation\Fakes\FakeGeneratedOutput;
use Cbox\Cms\Generators\Tests\Schema\Fakes\FakeSchemaSource;
use Cbox\Cms\Generators\Tests\SchemaFixtures;

/*
 * cms:generate's action called directly with its GenerationTarget, the fake schema source and
 * the fake output (GUARDRAILS 9). SchemaSourceBehaviour and GeneratedOutputBehaviour hold the
 * fakes to YamlSchemaSource and FilesystemGeneratedOutput.
 */

function generateCode(FakeSchemaSource $schemas, FakeGeneratedOutput $output): GenerateCode
{
    return new GenerateCode($schemas, new GeneratorRunner([new PhpTypeHandleEnum, new TypeScriptTypeHandles]), $output);
}

it('reads the target\'s schema, generates from it and writes the code below the root', function (): void {
    $schemas = new FakeSchemaSource;
    $schemas->put('/srv/app/schema/fixture.yaml', SchemaFixtures::schema(['page' => ['title' => 'text']]));
    $output = new FakeGeneratedOutput;
    $output->put('/srv/app/app/Cms/Generated/Stale.php', "<?php\n");

    $report = generateCode($schemas, $output)->generate(SchemaFixtures::target('/srv/app'));

    expect($schemas->loaded)->toBe(['/srv/app/schema/fixture.yaml'])
        ->and($report->written)->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts'])
        ->and($report->removed)->toBe(['app/Cms/Generated/Stale.php'])
        ->and($output->files('/srv/app'))->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts'])
        ->and($output->contents('/srv/app/app/Cms/Generated/TypeHandle.php'))->toContain('enum TypeHandle: string')
        ->and(generateCode($schemas, $output)->generate(SchemaFixtures::target('/srv/app'))->changed())->toBeFalse();
});

it('writes nothing when the schema cannot be read', function (): void {
    $output = new FakeGeneratedOutput;

    expect(static fn (): WriteReport => generateCode(new FakeSchemaSource, $output)->generate(SchemaFixtures::target('/srv/app')))
        ->toThrow(GenerationFailed::class, '[generate_schema_missing] The schema file /srv/app/schema/fixture.yaml does not exist')
        ->and($output->files('/srv/app'))->toBe([]);
});

it('passes on the failure of a schema file the source refuses', function (): void {
    $schemas = new FakeSchemaSource;
    $schemas->refuse('/srv/app/schema/fixture.yaml', GenerationFailed::because(GenerateErrorCode::SchemaSyntax, 'The schema file /srv/app/schema/fixture.yaml is not valid YAML.'));
    $output = new FakeGeneratedOutput;

    expect(static fn (): WriteReport => generateCode($schemas, $output)->generate(SchemaFixtures::target('/srv/app')))
        ->toThrow(GenerationFailed::class, '[generate_schema_syntax]')
        ->and($output->files('/srv/app'))->toBe([]);
});

it('passes on an output that cannot be written', function (): void {
    $schemas = new FakeSchemaSource;
    $schemas->put('/srv/app/schema/fixture.yaml', SchemaFixtures::schema(['page' => ['title' => 'text']]));
    $output = new FakeGeneratedOutput;
    $output->block('/srv/app/resources/js/cms/generated/index.ts');

    expect(static fn (): WriteReport => generateCode($schemas, $output)->generate(SchemaFixtures::target('/srv/app')))
        ->toThrow(GenerationFailed::class, '[generate_output_unwritable] The generated file /srv/app/resources/js/cms/generated/index.ts could not be written');
});
