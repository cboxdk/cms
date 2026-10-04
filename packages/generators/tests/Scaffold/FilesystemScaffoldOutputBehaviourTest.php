<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Generators\Scaffold\Adapter\FilesystemScaffoldOutput;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldOutput;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ScaffoldOutputBehaviour against the filesystem, in scratch directories. A path is blocked by
 * putting a directory where the file would go, which the rename into place cannot replace.
 */
final class FilesystemScaffoldOutputBehaviourTest extends TestCase
{
    use ScaffoldOutputBehaviour;

    #[Override]
    protected function tearDown(): void
    {
        SchemaFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function scaffoldOutput(): ScaffoldOutput
    {
        return new FilesystemScaffoldOutput;
    }

    #[Override]
    protected function scaffoldRoot(): string
    {
        return SchemaFixtures::scratch();
    }

    #[Override]
    protected function putFile(ScaffoldOutput $output, string $root, string $path, string $contents): void
    {
        SchemaFixtures::write($root.'/'.$path, $contents);
    }

    #[Override]
    protected function blockPath(ScaffoldOutput $output, string $root, string $path): void
    {
        mkdir($root.'/'.$path, 0o775, true);
    }

    #[Override]
    protected function contentsAt(ScaffoldOutput $output, string $root, string $path): ?string
    {
        return is_file($root.'/'.$path) ? (string) file_get_contents($root.'/'.$path) : null;
    }
}
