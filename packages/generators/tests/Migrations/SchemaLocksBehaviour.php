<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Migrations\Boundary\TypeTableLockJson;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Migrations\Domain\SchemaLocks;
use Cbox\Cms\Generators\Migrations\Domain\TableChanges;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SchemaLocks does (PRD 11.6), run against LockFiles in scratch directories and
 * against FakeSchemaLocks, so the fake the generator tests use cannot drift from the filesystem
 * (GUARDRAILS 9).
 */
trait SchemaLocksBehaviour
{
    abstract protected function schemaLocks(): SchemaLocks;

    /**
     * A new, empty absolute directory to read below.
     */
    abstract protected function locksRoot(): string;

    /**
     * Places a file below the root, as cms:generate or a person would have.
     */
    abstract protected function putFile(SchemaLocks $locks, string $root, string $path, string $contents): void;

    #[Test]
    public function a_directory_that_does_not_exist_has_no_locks(): void
    {
        Assert::assertSame([], $this->schemaLocks()->read($this->locksRoot(), 'database/migrations/cms'));
    }

    #[Test]
    public function it_reads_every_lock_in_the_directory_sorted_by_table_and_nothing_else(): void
    {
        [$alpha, $zeta] = $this->twoLocks();
        $locks = $this->schemaLocks();
        $root = $this->locksRoot();
        $this->putFile($locks, $root, 'database/migrations/cms/'.$zeta->file(), TypeTableLockJson::encode($zeta));
        $this->putFile($locks, $root, 'database/migrations/cms/'.$alpha->file(), TypeTableLockJson::encode($alpha));
        $this->putFile($locks, $root, 'database/migrations/cms/app__alpha_0001_create.php', "<?php\n");
        $this->putFile($locks, $root, 'database/migrations/cms/notes.txt', "{\n");
        $this->putFile($locks, $root, 'database/migrations/other/app__other.lock', "{\n");
        $this->putFile($locks, $root, 'database/migrations/cms/nested/app__nested.lock', "{\n");

        Assert::assertEquals([$alpha, $zeta], $locks->read($root, 'database/migrations/cms'));
    }

    #[Test]
    public function it_refuses_every_lock_that_is_not_what_cms_generate_writes(): void
    {
        [$alpha, $zeta] = $this->twoLocks();
        $locks = $this->schemaLocks();
        $root = $this->locksRoot();
        $this->putFile($locks, $root, 'database/migrations/cms/'.$alpha->file(), TypeTableLockJson::encode($alpha)."\n");
        $this->putFile($locks, $root, 'database/migrations/cms/'.$zeta->file(), 'not json');

        try {
            $locks->read($root, 'database/migrations/cms');
        } catch (GenerationFailed $failed) {
            Assert::assertSame([GenerateErrorCode::LockInvalid, GenerateErrorCode::LockInvalid], $failed->codes());
            Assert::assertStringContainsString('The schema lock database/migrations/cms/app__alpha.lock cannot be used: it is not in the form cms:generate writes.', $failed->getMessage());
            Assert::assertStringContainsString('The schema lock database/migrations/cms/app__zeta.lock cannot be used: it is not valid JSON', $failed->getMessage());

            return;
        }

        Assert::fail('The locks were read.');
    }

    /**
     * @return array{TypeTableLock, TypeTableLock}
     */
    private function twoLocks(): array
    {
        $locks = TableChanges::next(MigrationFixtures::compile([
            'zeta.yaml' => MigrationFixtures::note(typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbc1', handle: 'zeta'),
            'alpha.yaml' => MigrationFixtures::note(typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbc2', handle: 'alpha'),
        ]), []);

        return [$locks[0], $locks[1]];
    }
}
