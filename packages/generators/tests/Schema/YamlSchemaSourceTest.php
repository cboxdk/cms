<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\FixtureSchemaParser;
use Cbox\Cms\Generators\Schema\Boundary\YamlSchemaSource;
use Cbox\Cms\Generators\Schema\Domain\SchemaSource;
use Cbox\Cms\Generators\Schema\Domain\TypeDefinition;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use PHPUnit\Framework\Assert;

/*
 * The schema comes from a YAML file (PRD 11.12), read with symfony/yaml.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

function loadFailure(string $path): GenerationFailed
{
    try {
        new YamlSchemaSource(new FixtureSchemaParser)->load($path);
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The source accepted an invalid schema file.');
}

it('is the schema source in the container', function (): void {
    expect(app(SchemaSource::class))->toBeInstanceOf(YamlSchemaSource::class);
});

it('reads the M0 format from a YAML file', function (): void {
    $path = SchemaFixtures::scratch().'/fixture.yaml';
    SchemaFixtures::write($path, "format: m0-provisional\ntypes:\n  - handle: page\n    label: Page\n    fields:\n      - handle: title\n        type: text\n");

    $schema = new YamlSchemaSource(new FixtureSchemaParser)->load($path);

    expect(array_map(static fn (TypeDefinition $type): string => $type->handle->value, $schema->types))->toBe(['page']);
});

it('stops with generate_schema_missing when the file does not exist', function (): void {
    $path = SchemaFixtures::scratch().'/missing.yaml';

    $failed = loadFailure($path);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaMissing])
        ->and($failed->getMessage())->toContain($path.' does not exist or cannot be read');
});

it('stops with generate_schema_syntax when the file is not valid YAML', function (string $yaml): void {
    $path = SchemaFixtures::scratch().'/fixture.yaml';
    SchemaFixtures::write($path, $yaml);

    $failed = loadFailure($path);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaSyntax])
        ->and($failed->getMessage())->toContain('is not valid YAML');
})->with([
    'bad indentation' => "format: m0-provisional\n  types: [\n",
    'a duplicate key' => "format: m0-provisional\nformat: m0-provisional\ntypes: []\n",
    'a PHP object tag' => "format: !php/object 'O:8:\"stdClass\":0:{}'\ntypes: []\n",
]);

it('reports an empty file as a schema without the required keys', function (): void {
    $path = SchemaFixtures::scratch().'/fixture.yaml';
    SchemaFixtures::write($path, '');

    expect(loadFailure($path)->getMessage())->toContain('must be a mapping with the keys format, types');
});

it('treats a YAML date as a value of the wrong kind, not as text', function (): void {
    $path = SchemaFixtures::scratch().'/fixture.yaml';
    SchemaFixtures::write($path, "format: m0-provisional\ntypes:\n  - handle: page\n    label: 2026-09-26\n    fields:\n      - handle: title\n        type: text\n");

    expect(loadFailure($path)->getMessage())->toContain('types[0].label: must be a string');
});
