<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Generators\Editor\Domain\EditorLine;
use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\RichTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Illuminate\Contracts\Config\Repository;

/*
 * The workbench's schema root and its committed generated code (GUARDRAILS 2.6). Every file in
 * workbench/schema is a blueprint v1 file that YamlBlueprintSource reads without problems, and the
 * committed files are exactly what the generators produce from them: the same check as
 * `composer check:generated`, without writing. Every file starts with the editor line that
 * cms:schema:editor writes, so running it changes nothing.
 */

it('holds only blueprint v1 files, which the YAML source reads without problems', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $directory = $target->roots[0]->path();
    $files = SchemaFixtures::files($directory);

    $blueprints = app(BlueprintSource::class)->read($target->roots);

    expect(app(BlueprintSource::class))->toBeInstanceOf(YamlBlueprintSource::class)
        ->and(array_map(static fn (SchemaRoot $root): string => $root->owner->value.': '.$root->directory, $target->roots))->toBe(['app: workbench/schema'])
        ->and($files)->toBe(['article.yaml']);

    foreach ($files as $file) {
        expect((string) file_get_contents($directory.'/'.$file))->toMatch('/^blueprint: 1$/m');
    }

    expect($blueprints->extensions)->toBe([])
        ->and($blueprints->types)->toHaveCount(1);

    $article = $blueprints->types[0];

    expect($article->handle->value)->toBe('article')
        ->and($article->owner->value)->toBe('app')
        ->and($article->version)->toBe(1)
        ->and($article->typeId->value->value)->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/')
        ->and([$article->capabilities->history, $article->capabilities->stages, $article->capabilities->localization, $article->capabilities->routable])
        ->toBe([History::Full, Stages::DraftRelease, Localization::None, true])
        ->and(array_map(static fn (FieldBlueprint $field): array => [$field->handle->value, $field->options::class, $field->classification, $field->description !== null], $article->fields))
        ->toBe([
            ['title', TextOptions::class, Classification::Public, true],
            ['body', RichTextOptions::class, Classification::Public, true],
        ]);
});

it('has committed generated code that matches the schema', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $schema = SchemaResolver::resolve(app(BlueprintSource::class)->read($target->roots));
    $result = app(GeneratorRunner::class)->run($schema, $target);

    expect($result->paths())->toBe(['workbench/app/Cms/Generated/TypeHandle.php', 'workbench/resources/js/cms/generated/index.ts']);

    foreach ($result->files as $file) {
        expect(file_get_contents($target->root.'/'.$file->path))->toBe($file->contents, $file->path.' differs from what the schema generates. Run `vendor/bin/testbench cms:generate`.');
    }
});

it('starts every file with the editor line, through vendor to the installed blueprint schema', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $directory = $target->roots[0]->path();
    $schema = new BlueprintSchemaFile()->editorPath();
    $files = SchemaFixtures::files($directory);

    expect($files)->not->toBe([]);

    foreach ($files as $file) {
        $contents = (string) file_get_contents($directory.'/'.$file);
        $first = explode("\n", $contents, 2)[0];

        expect($first)->toStartWith(EditorLine::PREFIX, $file.' does not start with the editor line. Run `vendor/bin/testbench cms:schema:editor`.');

        $path = substr($first, strlen(EditorLine::PREFIX));

        expect($path)->toContain('vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json')
            ->and(realpath(dirname($directory.'/'.$file).'/'.$path))->toBe(realpath(dirname(__DIR__, 3).'/packages/contracts/resources/schemas/blueprint.v1.json'))
            ->and(EditorLine::towards($schema, (string) realpath(dirname($directory.'/'.$file)))->apply($contents))->toBe($contents, $file.' is not what cms:schema:editor writes. Run `vendor/bin/testbench cms:schema:editor`.');
    }
});
