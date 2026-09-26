<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Editor;

use Cbox\Cms\Generators\Editor\Domain\SchemaFiles;
use Cbox\Cms\Generators\Tests\Editor\Fakes\FakeSchemaFiles;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SchemaFilesBehaviour against the fake the editor's action tests use.
 */
final class FakeSchemaFilesBehaviourTest extends TestCase
{
    use SchemaFilesBehaviour;

    #[Override]
    protected function schemaFiles(): SchemaFiles
    {
        return new FakeSchemaFiles;
    }

    #[Override]
    protected function base(): string
    {
        return '/srv/app';
    }

    #[Override]
    protected function putFile(SchemaFiles $files, string $path, string $contents): void
    {
        $this->fake($files)->put($path, $contents);
    }

    #[Override]
    protected function makeDirectory(SchemaFiles $files, string $path): void
    {
        $this->fake($files)->directory($path);
    }

    #[Override]
    protected function blockFile(SchemaFiles $files, string $path): void
    {
        $this->fake($files)->block($path);
    }

    #[Override]
    protected function hideFile(SchemaFiles $files, string $path): void
    {
        $this->fake($files)->hide($path);
    }

    #[Override]
    protected function contentsAt(SchemaFiles $files, string $path): ?string
    {
        return $this->fake($files)->contents($path);
    }

    private function fake(SchemaFiles $files): FakeSchemaFiles
    {
        return $files instanceof FakeSchemaFiles ? $files : throw new LogicException('The case uses files this class did not make.');
    }
}
