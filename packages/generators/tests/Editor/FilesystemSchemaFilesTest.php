<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Editor;

use Cbox\Cms\Generators\Editor\Adapter\FilesystemSchemaFiles;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\SchemaFixtures;

/*
 * What only the filesystem has: permissions, symlinks, temporary files and real paths.
 * SchemaFilesBehaviour covers the rest.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

it('keeps the permissions of a file it writes and leaves no temporary file', function (): void {
    $base = SchemaFixtures::scratch();
    SchemaFixtures::write($base.'/schema/page.yaml', "blueprint: 1\n");
    chmod($base.'/schema/page.yaml', 0o640);
    $files = new FilesystemSchemaFiles;
    [$file] = $files->find([new SchemaRoot(Owner::app(), $base, 'schema')]);

    $files->write($file, "# line\nblueprint: 1\n");
    clearstatcache();

    expect(fileperms($base.'/schema/page.yaml') & 0o7777)->toBe(0o640)
        ->and(file_get_contents($base.'/schema/page.yaml'))->toBe("# line\nblueprint: 1\n")
        ->and(SchemaFixtures::files($base))->toBe(['schema/page.yaml']);
});

it('writes the file a symlink points at and keeps the symlink', function (): void {
    $base = SchemaFixtures::scratch();
    SchemaFixtures::write($base.'/shared/page.yaml', "blueprint: 1\n");
    mkdir($base.'/schema');
    symlink($base.'/shared/page.yaml', $base.'/schema/page.yaml');
    $files = new FilesystemSchemaFiles;
    [$file] = $files->find([new SchemaRoot(Owner::app(), $base, 'schema')]);

    $files->write($file, "# line\nblueprint: 1\n");

    expect(is_link($base.'/schema/page.yaml'))->toBeTrue()
        ->and(file_get_contents($base.'/shared/page.yaml'))->toBe("# line\nblueprint: 1\n")
        ->and(SchemaFixtures::files($base.'/shared'))->toBe(['page.yaml']);
});

it('gives a file the real path of the directory it was found in', function (): void {
    $base = SchemaFixtures::scratch();
    SchemaFixtures::write($base.'/real/schema/blog/article.yaml', "blueprint: 1\n");
    symlink($base.'/real', $base.'/linked');

    [$file] = new FilesystemSchemaFiles()->find([new SchemaRoot(Owner::app(), $base, 'linked/schema')]);

    expect($file->path)->toBe($base.'/linked/schema/blog/article.yaml')
        ->and($file->file)->toBe('linked/schema/blog/article.yaml')
        ->and($file->directory)->toBe($base.'/real/schema/blog');
});

it('refuses to write a file in a directory it cannot write to and keeps its bytes', function (): void {
    $base = SchemaFixtures::scratch();
    SchemaFixtures::write($base.'/schema/page.yaml', "blueprint: 1\n");
    $files = new FilesystemSchemaFiles;
    [$file] = $files->find([new SchemaRoot(Owner::app(), $base, 'schema')]);
    chmod($base.'/schema', 0o555);

    try {
        expect(static fn () => $files->write($file, "# line\nblueprint: 1\n"))
            ->toThrow(GenerationFailed::class, '[generate_schema_unwritable] schema/page.yaml cannot be written: ');
    } finally {
        chmod($base.'/schema', 0o775);
    }

    expect(file_get_contents($base.'/schema/page.yaml'))->toBe("blueprint: 1\n")
        ->and(SchemaFixtures::files($base))->toBe(['schema/page.yaml']);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');

it('refuses to write a file that is gone', function (): void {
    $base = SchemaFixtures::scratch();
    SchemaFixtures::write($base.'/schema/page.yaml', "blueprint: 1\n");
    $files = new FilesystemSchemaFiles;
    [$file] = $files->find([new SchemaRoot(Owner::app(), $base, 'schema')]);
    unlink($base.'/schema/page.yaml');

    expect(static fn () => $files->write($file, "# line\n"))
        ->toThrow(GenerationFailed::class, '[generate_schema_unwritable] schema/page.yaml cannot be written: the file no longer exists.')
        ->and(SchemaFixtures::files($base))->toBe([]);
});
