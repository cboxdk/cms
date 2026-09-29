<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Migrations\Boundary\LockFiles;
use Cbox\Cms\Generators\Migrations\Domain\SchemaLocks;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SchemaLocksBehaviour against LockFiles in scratch directories.
 */
final class LockFilesSchemaLocksBehaviourTest extends TestCase
{
    use SchemaLocksBehaviour;

    #[Override]
    protected function tearDown(): void
    {
        SchemaFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function schemaLocks(): SchemaLocks
    {
        return new LockFiles;
    }

    #[Override]
    protected function locksRoot(): string
    {
        return SchemaFixtures::scratch();
    }

    #[Override]
    protected function putFile(SchemaLocks $locks, string $root, string $path, string $contents): void
    {
        SchemaFixtures::write($root.'/'.$path, $contents);
    }
}
