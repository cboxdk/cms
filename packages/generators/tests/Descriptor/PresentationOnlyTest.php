<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Generators\Descriptor\Boundary\TypeDescriptorJson;
use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Boundary\TypeScriptRuntime;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeEditors;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeQueries;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeValidators;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptContracts;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeTableMigrations;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Migrations\Boundary\TypeTableLockJson;
use Cbox\Cms\Generators\Protocol\Boundary\KernelContracts;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Migrations\Fakes\FakeSchemaLocks;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Generators\TypeEditors\Boundary\DefaultJson;
use Cbox\Cms\Generators\TypeEditors\Boundary\RuleJson;
use Cbox\Cms\Generators\TypeEditors\Domain\TypeEditorCompiler;
use Illuminate\Support\ServiceProvider;

/*
 * The presentation keys are presentation only (gap G2, B2-T72): a copy of the comprehensive
 * example whose layout, ui, a default and a label per locale differ from the committed example
 * compiles to the same type descriptor, and every generator writes the same PHP, TypeScript and
 * migrations, except the editor descriptors, which differ. So the keys reach neither the catalog,
 * the records, the codecs, the validators, the query builders nor the tables.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * A copy of the comprehensive example's blueprints in a scratch directory, with the given
 * replacements applied to the shop's type file.
 *
 * @param  array<string, string>  $replacements  search to replacement
 * @return array{string, list<SchemaRoot>} the scratch base and the roots
 */
function presentationCopy(array $replacements): array
{
    $base = SchemaFixtures::scratch();
    $product = (string) file_get_contents(ComprehensiveExample::DIRECTORY.'/shop/product.yaml');

    foreach ($replacements as $search => $replacement) {
        expect($product)->toContain($search);
        $product = str_replace($search, $replacement, $product);
    }

    SchemaFixtures::write($base.'/shop/product.yaml', $product);
    SchemaFixtures::write($base.'/app/product.yaml', (string) file_get_contents(ComprehensiveExample::DIRECTORY.'/app/product.yaml'));

    return [$base, [new SchemaRoot(new Owner('app'), $base, 'app'), new SchemaRoot(new Owner('shop'), $base, 'shop')]];
}

/**
 * @param  list<SchemaRoot>  $roots
 */
function presentationSchema(array $roots): CompiledSchema
{
    return DescriptorCompiler::compile(SchemaResolver::resolve(ComprehensiveExample::source()->read($roots)));
}

/**
 * Everything the generators write for the schema, as path to contents, sorted by path, with the
 * files that name the scratch base made comparable.
 *
 * @return array<string, string>
 */
function presentationOutput(CompiledSchema $schema, string $base): array
{
    $target = new GenerationTarget($base, [new SchemaRoot(new Owner('app'), $base, 'app'), new SchemaRoot(new Owner('shop'), $base, 'shop')], 'Generated', ComprehensiveExample::PHP_NAMESPACE, 'typescript/generated', 'migrations/cms');
    $runner = new GeneratorRunner([
        new PhpRecordDtos,
        new PhpTypeHandleEnum,
        new PhpRecords,
        new PhpTypeCatalog(ServiceProvider::class),
        new PhpTypeEditors(new TypeEditorCompiler(RuleJson::of(...), DefaultJson::of(...), RuleJson::condition(...))),
        new PhpTypeQueries,
        new PhpTypeValidators,
        new TypeScriptTypeHandles,
        new TypeScriptContracts(new TypeScriptRuntime()->source(...), new KernelContracts()->read(...)),
        new TypeTableMigrations(new FakeSchemaLocks, TypeTableLockJson::encode(...)),
    ]);
    $files = [];

    foreach ($runner->run($schema, $target)->files as $file) {
        $files[$file->path] = str_replace($base, '<base>', $file->contents);
    }

    ksort($files, SORT_STRING);

    return $files;
}

it('changes only the editor descriptor when the layout, a hint, a default and a label per locale change', function (): void {
    [$base, $roots] = presentationCopy([]);
    [$changedBase, $changedRoots] = presentationCopy([
        // The layout: the colour moves from the first section to the sidebar, at full width.
        "            - field: colour\n              width: third\n" => '',
        "          - field: discontinued\n" => "          - field: colour\n          - field: discontinued\n",
        // A hint: the name's input hint, and the colour shown as a dropdown.
        'input_hint: The name on the shelf' => 'input_hint: What the label says',
        'display: radio' => 'display: dropdown',
        // A default: the stock starts at 10.
        "    default: 0\n" => "    default: 10\n",
        // A label per locale: the Danish name of the type and of the first field, after the English text the single-text places use.
        "  da: Produkt\n" => "  da: Vare\n",
        "      da: Navn\n" => "      da: Betegnelse\n",
    ]);

    $schema = presentationSchema($roots);
    $changed = presentationSchema($changedRoots);
    $output = presentationOutput($schema, $base);
    $changedOutput = presentationOutput($changed, $changedBase);
    $editors = array_filter(array_keys($output), static fn (string $path): bool => str_ends_with($path, PhpTypeEditors::EDITORS.'.php'));

    expect(TypeDescriptorJson::encode($changed->types[0]))->toBe(TypeDescriptorJson::encode($schema->types[0]))
        ->and(array_keys($changedOutput))->toBe(array_keys($output))
        ->and($editors)->toHaveCount(1);

    foreach ($output as $path => $contents) {
        if (in_array($path, $editors, true)) {
            expect($changedOutput[$path])->not->toBe($contents, $path);
        } else {
            expect($changedOutput[$path])->toBe($contents, $path);
        }
    }
});

it('gives the same type descriptor with and without the presentation keys, so a change of them is no new version of the type', function (): void {
    [, $roots] = presentationCopy([]);
    $product = (string) file_get_contents(ComprehensiveExample::DIRECTORY.'/shop/product.yaml');
    $stripped = (string) preg_replace('/^layout:\n.*\z/ms', '', $product);
    $stripped = (string) preg_replace('/^ +(ui:\n(?: {6,}.*\n)+| +default: .*\n)/m', '', $stripped);
    $stripped = (string) preg_replace('/^( *)(label|description):\n\1  en: (.*)\n(?:\1  [a-z-]+: .*\n)+/m', '$1$2: $3'."\n", $stripped);
    $withoutBase = SchemaFixtures::scratch();
    SchemaFixtures::write($withoutBase.'/shop/product.yaml', $stripped);
    SchemaFixtures::write($withoutBase.'/app/product.yaml', (string) preg_replace('/^layout:\n.*\z/ms', '', (string) file_get_contents(ComprehensiveExample::DIRECTORY.'/app/product.yaml')));
    $withoutRoots = [new SchemaRoot(new Owner('app'), $withoutBase, 'app'), new SchemaRoot(new Owner('shop'), $withoutBase, 'shop')];

    expect($stripped)->not->toContain('layout:');
    expect($stripped)->not->toContain('ui:');
    expect($stripped)->not->toContain('default:');
    expect($stripped)->not->toContain('  da: ');
    expect(TypeDescriptorJson::encode(presentationSchema($withoutRoots)->types[0]))->toBe(TypeDescriptorJson::encode(presentationSchema($roots)->types[0]));
    expect(TypeDescriptorJson::encode(presentationSchema($roots)->types[0]))->toBe((string) file_get_contents(ComprehensiveExample::GOLDEN));
});
