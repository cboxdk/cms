<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation;

use Cbox\Cms\Generators\Generation\Adapter\FilesystemGeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Override;
use PHPUnit\Framework\TestCase;
use SplFileObject;

/**
 * GeneratedOutputBehaviour against FilesystemGeneratedOutput in scratch directories. A blocked
 * path is a directory where the file should be, which no rename can replace, whoever runs the
 * test.
 */
final class FilesystemGeneratedOutputBehaviourTest extends TestCase
{
    use GeneratedOutputBehaviour;

    #[Override]
    protected function tearDown(): void
    {
        SchemaFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function generatedOutput(): GeneratedOutput
    {
        return new FilesystemGeneratedOutput;
    }

    #[Override]
    protected function outputRoot(): string
    {
        return SchemaFixtures::scratch();
    }

    #[Override]
    protected function putFile(GeneratedOutput $output, string $root, string $path, string $contents): void
    {
        SchemaFixtures::write($root.'/'.$path, $contents);
    }

    #[Override]
    protected function blockPath(GeneratedOutput $output, string $root, string $path): void
    {
        mkdir($root.'/'.$path.'/occupied', 0o775, true);
    }

    #[Override]
    protected function contentsAt(GeneratedOutput $output, string $root, string $path): ?string
    {
        if (! is_file($root.'/'.$path)) {
            return null;
        }

        $file = new SplFileObject($root.'/'.$path, 'rb');
        $size = $file->getSize();
        $contents = $size === 0 ? '' : $file->fread($size);

        return is_string($contents) ? $contents : null;
    }

    #[Override]
    protected function filesBelow(GeneratedOutput $output, string $root): array
    {
        return SchemaFixtures::files($root);
    }
}
