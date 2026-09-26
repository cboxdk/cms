<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Schema\Domain\FixtureSchema;
use Cbox\Cms\Generators\Schema\Domain\SchemaSource;
use Cbox\Cms\Generators\Schema\Domain\TypeDefinition;
use Illuminate\Contracts\Config\Repository;

/*
 * The workbench's fixture schema and its committed generated code (GUARDRAILS 2.6). The same
 * check as `composer check:generated`, without writing: the committed files are exactly what the
 * generators produce from the committed schema.
 */

it('has one type in the provisional M0 format', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $schema = app(SchemaSource::class)->load($target->root.'/'.$target->schema);

    expect((string) file_get_contents($target->root.'/'.$target->schema))->toContain("\nformat: ".FixtureSchema::FORMAT."\n")
        ->and(array_map(static fn (TypeDefinition $type): string => $type->handle->value, $schema->types))->toBe(['article']);
});

it('has committed generated code that matches the schema', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $schema = app(SchemaSource::class)->load($target->root.'/'.$target->schema);
    $result = app(GeneratorRunner::class)->run($schema, $target);

    expect($result->paths())->toBe(['workbench/app/Cms/Generated/TypeHandle.php', 'workbench/resources/js/cms/generated/index.ts']);

    foreach ($result->files as $file) {
        expect(file_get_contents($target->root.'/'.$file->path))->toBe($file->contents, $file->path.' differs from what the schema generates. Run `vendor/bin/testbench cms:generate`.');
    }
});
