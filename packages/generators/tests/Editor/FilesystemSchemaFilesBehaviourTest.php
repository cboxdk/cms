<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Editor;

use Cbox\Cms\Generators\Editor\Adapter\FilesystemSchemaFiles;
use Cbox\Cms\Generators\Editor\Domain\SchemaFiles;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SchemaFilesBehaviour against FilesystemSchemaFiles in a scratch directory.
 */
final class FilesystemSchemaFilesBehaviourTest extends TestCase
{
    use SchemaFilesBehaviour;

    private ?string $base = null;

    #[Override]
    protected function tearDown(): void
    {
        SchemaFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function schemaFiles(): SchemaFiles
    {
        return new FilesystemSchemaFiles;
    }

    #[Override]
    protected function base(): string
    {
        return $this->base ??= SchemaFixtures::scratch();
    }

    #[Override]
    protected function putFile(SchemaFiles $files, string $path, string $contents): void
    {
        SchemaFixtures::write($path, $contents);
    }

    #[Override]
    protected function makeDirectory(SchemaFiles $files, string $path): void
    {
        mkdir($path, 0o775, true);
    }

    #[Override]
    protected function blockFile(SchemaFiles $files, string $path): void
    {
        chmod($path, 0o444);
    }

    #[Override]
    protected function hideFile(SchemaFiles $files, string $path): void
    {
        chmod($path, 0o000);
    }

    #[Override]
    protected function contentsAt(SchemaFiles $files, string $path): ?string
    {
        return LocalFile::contents($path);
    }
}
