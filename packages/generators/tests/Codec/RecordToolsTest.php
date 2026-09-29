<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;

/*
 * The record DTOs and codecs an application gets (PRD 8.9, GUARDRAILS 2.2): generated in the
 * default namespace App\Cms\Generated, outside Cbox\Cms, for types with every core field type, a
 * type of a module and an extension of it, they pass Pint, Rector and PHPStan level 10 unchanged,
 * with the testkit's rules: the DTOs sit in Domain\Dto without mixed or array shapes, the codecs in
 * Boundary, and neither uses anything of the kernel that is #[Internal].
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

it('generates records that Pint, Rector and PHPStan accept unchanged in an application', function (): void {
    $directory = SchemaFixtures::scratch();
    $app = SchemaFixtures::root(base: $directory);
    $acme = SchemaFixtures::root('acme', 'vendor/acme/schema', $directory);
    $fields = [];

    foreach (new FieldTypeRegistry(new CoreFieldTypes)->names() as $type) {
        $fields['a_'.$type] = $type;
    }

    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text', 'parts' => 'group']);
    $schema = SchemaFixtures::compiled(new Blueprints([
        SchemaFixtures::type($app, 'every_field', $fields),
        $product,
        SchemaFixtures::type($app, 'product', ['sku' => 'text']),
    ], [
        SchemaFixtures::extension($app, 'shop/product.yaml', $product->typeId, ['tax_code' => 'text', 'extra' => 'group']),
    ]));
    $target = new GenerationTarget($directory, [$acme, $app], 'app/Cms/Generated', 'App\Cms\Generated', 'resources/js/cms/generated');

    foreach (new PhpRecordDtos()->generate($schema, $target) as $file) {
        SchemaFixtures::write($directory.'/'.$file->path, $file->contents);
    }

    $generated = $directory.'/app/Cms/Generated';
    $pint = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/pint.php', '--test', '--config='.Phpstan::root().'/pint.json', $generated], Phpstan::root());
    $pint->run();
    $rector = new Process([Phpstan::root().'/vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar', $generated], Phpstan::root());
    $rector->run();
    $analysis = Phpstan::analyse($generated);

    expect(SchemaFixtures::files($generated))->toBe([
        'Boundary/AcmeProductCodecV1.php',
        'Boundary/AppEveryFieldCodecV1.php',
        'Boundary/AppProductCodecV1.php',
        'Domain/Dto/AcmeProductV1.php',
        'Domain/Dto/AcmeProductV1Ext.php',
        'Domain/Dto/AcmeProductV1ExtApp.php',
        'Domain/Dto/AcmeProductV1ExtAppExtra.php',
        'Domain/Dto/AcmeProductV1Parts.php',
        'Domain/Dto/AppEveryFieldV1.php',
        'Domain/Dto/AppEveryFieldV1AGroup.php',
        'Domain/Dto/AppProductV1.php',
    ])
        ->and($pint->getExitCode())->toBe(0, $pint->getOutput())
        ->and($rector->getExitCode())->toBe(0, $rector->getOutput())
        ->and($analysis->identifiers)->toBe([])
        ->and($analysis->exitCode)->toBe(0);
});
