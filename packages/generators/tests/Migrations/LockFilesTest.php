<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Migrations\Boundary\LockFiles;
use Cbox\Cms\Generators\Tests\SchemaFixtures;

/*
 * LockFiles reads the schema locks from a local directory only (GUARDRAILS 3), and says which lock
 * it could not read.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

it('refuses a root that names a stream wrapper before any file function sees it', function (): void {
    $failed = MigrationFixtures::failure(static fn (): array => new LockFiles()->read('ftp://example.test/app', 'database/migrations/cms'));

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidConfig])
        ->and($failed->getMessage())->toContain('The migrations directory ftp://example.test/app/database/migrations/cms names a stream wrapper');
});

it('names a lock it cannot read', function (): void {
    $root = SchemaFixtures::scratch();
    SchemaFixtures::write($root.'/database/migrations/cms/app__note.lock', "{\n");
    chmod($root.'/database/migrations/cms/app__note.lock', 0o000);

    $failed = MigrationFixtures::failure(static fn (): array => new LockFiles()->read($root, 'database/migrations/cms'));
    chmod($root.'/database/migrations/cms/app__note.lock', 0o644);

    expect($failed->codes())->toBe([GenerateErrorCode::LockInvalid])
        ->and($failed->getMessage())->toContain('The schema lock database/migrations/cms/app__note.lock cannot be read.');
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');

it('says when the migrations directory cannot be listed', function (): void {
    $root = SchemaFixtures::scratch();
    mkdir($root.'/database/migrations/cms', 0o775, true);
    chmod($root.'/database/migrations/cms', 0o000);

    $failed = MigrationFixtures::failure(static fn (): array => new LockFiles()->read($root, 'database/migrations/cms'));
    chmod($root.'/database/migrations/cms', 0o775);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaMissing])
        ->and($failed->getMessage())->toContain('The migrations directory '.$root.'/database/migrations/cms cannot be listed');
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');
