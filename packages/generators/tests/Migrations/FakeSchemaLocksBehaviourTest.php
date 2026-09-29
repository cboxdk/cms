<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Migrations\Domain\SchemaLocks;
use Cbox\Cms\Generators\Tests\Migrations\Fakes\FakeSchemaLocks;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SchemaLocksBehaviour against the fake the generator tests use.
 */
final class FakeSchemaLocksBehaviourTest extends TestCase
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
        return new FakeSchemaLocks;
    }

    #[Override]
    protected function locksRoot(): string
    {
        return '/srv/app';
    }

    #[Override]
    protected function putFile(SchemaLocks $locks, string $root, string $path, string $contents): void
    {
        ($locks instanceof FakeSchemaLocks ? $locks : throw new LogicException('The case uses locks this class did not make.'))->put($root.'/'.$path, $contents);
    }
}
