<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Actions;

use Cbox\Cms\Generators\Editor\Actions\WriteEditorLines;
use Cbox\Cms\Generators\Editor\Domain\Dto\EditorReport;
use Cbox\Cms\Generators\Editor\Domain\Dto\EditorTarget;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Tests\Editor\Fakes\FakeSchemaFiles;
use Cbox\Cms\Generators\Tests\SchemaFixtures;

/*
 * cms:schema:editor's action called directly with its EditorTarget and the fake blueprint files
 * (GUARDRAILS 9). SchemaFilesBehaviour holds the fake to FilesystemSchemaFiles.
 */

const EDITOR_TARGET_SCHEMA = '/srv/app/vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json';

const EDITOR_TARGET_LINE = '# yaml-language-server: $schema=../vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json';

/**
 * The application's root, a module's root outside vendor/ and an addon's root below vendor/.
 */
function editorTarget(): EditorTarget
{
    return new EditorTarget([
        SchemaFixtures::root(),
        SchemaFixtures::root('acme', 'vendor/acme/shop/schema'),
        SchemaFixtures::root('shop', 'modules/shop/schema'),
    ], EDITOR_TARGET_SCHEMA);
}

it('adds the line relative to each file\'s directory, writes only the files it changes and names them', function (): void {
    $files = new FakeSchemaFiles;
    $files->put('/srv/app/schema/page.yaml', "# The page.\nblueprint: 1\n");
    $files->put('/srv/app/schema/blog/article.yaml', "# yaml-language-server: \$schema=../../vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json\nblueprint: 1\n");
    $files->put('/srv/app/schema/blog/post.yaml', "blueprint: 1\n");
    $files->put('/srv/app/modules/shop/schema/product.yaml', "blueprint: 1\n");
    $files->put('/srv/app/vendor/acme/shop/schema/product.yaml', "blueprint: 1\n");

    $report = new WriteEditorLines($files)->write(editorTarget());

    expect($report)->toEqual(new EditorReport(
        ['modules/shop/schema/product.yaml', 'schema/blog/post.yaml', 'schema/page.yaml'],
        ['schema/blog/article.yaml'],
        [],
        ['vendor/acme/shop/schema'],
    ))
        ->and($files->contents('/srv/app/schema/page.yaml'))->toBe(EDITOR_TARGET_LINE."\n# The page.\nblueprint: 1\n")
        ->and($files->contents('/srv/app/schema/blog/post.yaml'))->toBe("# yaml-language-server: \$schema=../../vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json\nblueprint: 1\n")
        ->and($files->contents('/srv/app/modules/shop/schema/product.yaml'))->toBe("# yaml-language-server: \$schema=../../../vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json\nblueprint: 1\n")
        ->and($files->contents('/srv/app/vendor/acme/shop/schema/product.yaml'))->toBe("blueprint: 1\n")
        ->and($files->writes)->toBe(['/srv/app/modules/shop/schema/product.yaml', '/srv/app/schema/blog/post.yaml', '/srv/app/schema/page.yaml']);
});

it('skips every schema root below vendor/ and names it, without listing, reading or writing its files', function (): void {
    $files = new FakeSchemaFiles;
    $files->put('/srv/app/schema/page.yaml', "blueprint: 1\n");
    $files->put('/srv/app/vendor/acme/shop/schema/product.yaml', "blueprint: 1\n");
    $files->put('/srv/app/vendor/acme/extra/schema/other.yaml', "blueprint: 1\n");
    $files->block('/srv/app/vendor/acme/shop/schema/product.yaml');
    $files->hide('/srv/app/vendor/acme/extra/schema/other.yaml');

    $report = new WriteEditorLines($files)->write(new EditorTarget([
        SchemaFixtures::root(),
        SchemaFixtures::root('acme', 'vendor/acme/shop/schema'),
        SchemaFixtures::root('extra', 'vendor/acme/extra/schema'),
        SchemaFixtures::root('gone', 'vendor/gone/schema'),
    ], EDITOR_TARGET_SCHEMA));

    expect($report)->toEqual(new EditorReport(
        ['schema/page.yaml'],
        [],
        [],
        ['vendor/acme/extra/schema', 'vendor/acme/shop/schema', 'vendor/gone/schema'],
    ))
        ->and($files->contents('/srv/app/vendor/acme/shop/schema/product.yaml'))->toBe("blueprint: 1\n")
        ->and($files->writes)->toBe(['/srv/app/schema/page.yaml']);
});

it('lists no files when every schema root is below vendor/', function (): void {
    $files = new FakeSchemaFiles;
    $files->put('/srv/app/vendor/acme/shop/schema/product.yaml', "blueprint: 1\n");

    $report = new WriteEditorLines($files)->write(new EditorTarget([
        SchemaFixtures::root('acme', 'vendor/acme/shop/schema'),
        SchemaFixtures::root('gone', 'vendor/gone/schema'),
    ], EDITOR_TARGET_SCHEMA));

    expect($report)->toEqual(new EditorReport([], [], [], ['vendor/acme/shop/schema', 'vendor/gone/schema']))
        ->and($files->contents('/srv/app/vendor/acme/shop/schema/product.yaml'))->toBe("blueprint: 1\n")
        ->and($files->writes)->toBe([]);
});

it('changes nothing the second time', function (): void {
    $files = new FakeSchemaFiles;
    $files->put('/srv/app/schema/page.yaml', "# yaml-language-server: \$schema=../wrong.json\nblueprint: 1\n");
    $files->directory('/srv/app/modules/shop/schema');
    new WriteEditorLines($files)->write(editorTarget());
    $files->writes = [];

    $report = new WriteEditorLines($files)->write(editorTarget());

    expect($report)->toEqual(new EditorReport([], ['schema/page.yaml'], [], ['vendor/acme/shop/schema']))
        ->and($files->writes)->toBe([])
        ->and($files->contents('/srv/app/schema/page.yaml'))->toBe(EDITOR_TARGET_LINE."\nblueprint: 1\n");
});

it('replaces a wrong line instead of adding a second one', function (): void {
    $files = new FakeSchemaFiles;
    $files->put('/srv/app/schema/page.yaml', "# yaml-language-server: \$schema=../packages/contracts/resources/schemas/blueprint.v1.json\n# The page.\nblueprint: 1\n");
    $files->directory('/srv/app/modules/shop/schema');

    $report = new WriteEditorLines($files)->write(editorTarget());

    expect($report->changed)->toBe(['schema/page.yaml'])
        ->and($files->contents('/srv/app/schema/page.yaml'))->toBe(EDITOR_TARGET_LINE."\n# The page.\nblueprint: 1\n");
});

it('reports a file it cannot read or write and still edits the others', function (): void {
    $files = new FakeSchemaFiles;
    $files->put('/srv/app/schema/a.yaml', "blueprint: 1\n");
    $files->put('/srv/app/schema/b.yaml', "blueprint: 1\n");
    $files->put('/srv/app/schema/c.yaml', "blueprint: 1\n");
    $files->put('/srv/app/schema/d.yaml', EDITOR_TARGET_LINE."\nblueprint: 1\n");
    $files->directory('/srv/app/modules/shop/schema');
    $files->block('/srv/app/schema/a.yaml');
    $files->block('/srv/app/schema/d.yaml');
    $files->hide('/srv/app/schema/b.yaml');

    $report = new WriteEditorLines($files)->write(editorTarget());

    expect($report->changed)->toBe(['schema/c.yaml'])
        ->and($report->unchanged)->toBe(['schema/d.yaml'])
        ->and(array_map(static fn (GenerationProblem $problem): string => $problem->describe(), $report->problems))->toBe([
            '[generate_schema_missing] schema/b.yaml cannot be read. Check its permissions.',
            '[generate_schema_unwritable] schema/a.yaml cannot be written: the file is read-only. Its editor line was not changed; make it writable and run cms:schema:editor again.',
        ])
        ->and($files->contents('/srv/app/schema/a.yaml'))->toBe("blueprint: 1\n")
        ->and($files->writes)->toBe(['/srv/app/schema/c.yaml']);
});

it('refuses roots it cannot list and writes nothing', function (): void {
    $files = new FakeSchemaFiles;
    $files->put('/srv/app/schema/page.yaml', "blueprint: 1\n");

    expect(static fn (): EditorReport => new WriteEditorLines($files)->write(editorTarget()))
        ->toThrow(GenerationFailed::class, '[generate_schema_missing] The schema root /srv/app/modules/shop/schema of shop does not exist')
        ->and($files->writes)->toBe([]);
});

it('reports a schema it cannot reach by a relative path', function (): void {
    $files = new FakeSchemaFiles;
    $files->put('/srv/app/schema/page.yaml', "blueprint: 1\n");

    $report = new WriteEditorLines($files)->write(new EditorTarget([SchemaFixtures::root()], 'D:\vendor\blueprint.v1.json'));

    expect($report->changed)->toBe([])
        ->and(array_map(static fn (GenerationProblem $problem): GenerateErrorCode => $problem->code, $report->problems))->toBe([GenerateErrorCode::InvalidConfig])
        ->and($files->writes)->toBe([]);
});
