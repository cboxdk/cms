<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Testkit\Phpstan\CredentialTables;
use Cbox\Cms\Testkit\Phpstan\KernelTables;
use Cbox\Cms\Testkit\Phpstan\KernelTableWriteRule;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 10, PRD 6.5 invariants 1 and 13 and 11.12: only the kernel writes its tables. The fixture
 * writes them through the query builder, the DB facade, raw SQL and PDO in an addon, its tests and
 * the testkit outside its fixture writers, and the same write sits in the core and in the fixture
 * writers, where it is allowed.
 *
 * @extends RuleTestCase<Rule<CallLike>>
 */
final class KernelTableWriteRuleTest extends RuleTestCase
{
    use ReportedErrors;

    private ?string $coreDirectory = null;

    private ?string $identityDirectory = null;

    public function test_it_reports_every_write_to_a_kernel_table_outside_the_kernel_as_non_ignorable(): void
    {
        // Not reported: reads, the addon's own table and an upsert clause (lines 37 to 43), the
        // core (line 67), the fixture writers (line 79).
        self::assertSame([
            '19 cboxCms.kernelTableWrite',   // table('actors')->insert()
            '20 cboxCms.kernelTableWrite',   // DB::table('nodes')->where()->update()
            '21 cboxCms.kernelTableWrite',   // table('public.entries as e')->delete()
            '22 cboxCms.kernelTableWrite',   // query()->from('sites')->upsert()
            '23 cboxCms.kernelTableWrite',   // DB::table('"variant_heads"')->truncate()
            '24 cboxCms.kernelTableWrite',   // a managed partition of events_interactive
            '29 cboxCms.kernelTableWrite',   // statement('insert into receipts ...')
            '30 cboxCms.kernelTableWrite',   // DB::statement('UPDATE "public"."placements" ...')
            '31 cboxCms.kernelTableWrite',   // delete('delete from only revisions ...')
            '32 cboxCms.kernelTableWrite',   // truncate table ..., changesets
            '32 cboxCms.kernelTableWrite',   // ..., head_snapshots
            '33 cboxCms.kernelTableWrite',   // an update in a CTE of a select
            '34 cboxCms.kernelTableWrite',   // PDO::exec('merge into mount_overrides ...')
            '55 cboxCms.kernelTableWrite',   // the addon's test code
            '91 cboxCms.kernelTableWrite',   // a copy of the fixture writer's write elsewhere in the testkit
            '103 cboxCms.kernelTableWrite',  // a namespace that only starts like the core's
        ], $this->reported('KernelTableWrites'));
    }

    public function test_the_message_names_the_table_and_the_fixture_writers(): void
    {
        $this->analyse([self::fixture('KernelTableWrites')], [
            [$this->message('actors'), 19],
            [$this->message('nodes'), 20],
            [$this->message('entries'), 21],
            [$this->message('sites'), 22],
            [$this->message('variant_heads'), 23],
            [$this->message('events_interactive'), 24],
            [$this->message('receipts'), 29],
            [$this->message('placements'), 30],
            [$this->message('revisions'), 31],
            [$this->message('changesets'), 32],
            [$this->message('head_snapshots'), 32],
            [$this->message('node_routes'), 33],
            [$this->message('mount_overrides'), 34],
            [$this->message('actors'), 55],
            [$this->message('actors'), 91],
            [$this->message('actors'), 103],
        ]);
    }

    public function test_it_reports_a_migration_in_the_global_namespace_outside_the_core(): void
    {
        self::assertSame(['12 cboxCms.kernelTableWrite', '13 cboxCms.kernelTableWrite'], $this->reported('KernelTableWriteMigration'));
    }

    public function test_it_allows_a_migration_in_the_global_namespace_in_the_core_modules_directory(): void
    {
        $this->coreDirectory = __DIR__.'/Fixtures';

        self::assertSame([], $this->reported('KernelTableWriteMigration'));
    }

    public function test_it_reads_the_kernel_tables_a_statement_writes_and_nothing_a_read_or_another_table(): void
    {
        self::assertSame(['nodes'], KernelTableWriteRule::tablesWrittenBy('INSERT INTO nodes (id) VALUES (1)'));
        self::assertSame(['sites'], KernelTableWriteRule::tablesWrittenBy("update\n  public . \"sites\" set handle = 'a'"));
        self::assertSame(['actors', 'nodes'], KernelTableWriteRule::tablesWrittenBy('truncate only actors , public.nodes restart identity'));
        self::assertSame(['events'], KernelTableWriteRule::tablesWrittenBy('copy events from stdin'));
        self::assertSame(['receipts'], KernelTableWriteRule::tablesWrittenBy('with a as (delete from receipts_p20260929 returning 1) select 1'));
        self::assertSame([], KernelTableWriteRule::tablesWrittenBy('select * from nodes for update skip locked'));
        self::assertSame([], KernelTableWriteRule::tablesWrittenBy('insert into shop_nodes values (1) on conflict do update set id = 1'));
        self::assertSame([], KernelTableWriteRule::tablesWrittenBy('update nodes_archive set x = 1'));
        self::assertSame('receipts_standard', KernelTables::of('RECEIPTS_STANDARD'));
        self::assertSame('receipts_standard', KernelTables::of('public."receipts_standard_p20260929"'));
        self::assertNull(KernelTables::of('receipts_px'));
    }

    public function test_it_reports_every_write_method_every_database_root_and_every_form_of_sql(): void
    {
        // Not reported: a root that is no database (lines 59 and 60), a later from() that names
        // another table (line 61), names that are not identifiers (lines 62, 63, 77, 78 and 89),
        // methods and classes that take no SQL (lines 79 to 88), a function call (line 102).
        $this->analyse([self::fixture('KernelTableWriteCalls')], [
            [$this->message('actors'), 40],
            [$this->message('audit'), 41],
            [$this->message('entries'), 42],
            [$this->message('grants'), 43],
            [$this->message('roles'), 44],
            [$this->message('sites'), 45],
            [$this->message('nodes'), 46],
            [$this->message('events'), 47],
            [$this->message('actors'), 57],
            [$this->message('audit'), 58],
            [$this->message('actors'), 73],
            [$this->message('audit'), 74],
            [$this->message('entries'), 74],
            [$this->message('grants'), 75],
            [$this->message('roles'), 75],
            [$this->message('sites'), 76],
            [$this->message('placements'), 99],
            [$this->message('revisions'), 100],
            [$this->message('variant_heads'), 101],
        ]);
    }

    public function test_it_reports_each_table_a_call_writes_once_as_non_ignorable(): void
    {
        self::assertSame([
            '40 cboxCms.kernelTableWrite',
            '41 cboxCms.kernelTableWrite',
            '42 cboxCms.kernelTableWrite',
            '43 cboxCms.kernelTableWrite',
            '44 cboxCms.kernelTableWrite',
            '45 cboxCms.kernelTableWrite',
            '46 cboxCms.kernelTableWrite',
            '47 cboxCms.kernelTableWrite',
            '57 cboxCms.kernelTableWrite',
            '58 cboxCms.kernelTableWrite',
            '73 cboxCms.kernelTableWrite',
            '74 cboxCms.kernelTableWrite',
            '74 cboxCms.kernelTableWrite',
            '75 cboxCms.kernelTableWrite',
            '75 cboxCms.kernelTableWrite',
            '76 cboxCms.kernelTableWrite',
            '99 cboxCms.kernelTableWrite',
            '100 cboxCms.kernelTableWrite',
            '101 cboxCms.kernelTableWrite',
        ], $this->reported('KernelTableWriteCalls'));
    }

    public function test_it_allows_a_migration_in_the_global_namespace_below_the_core_module_by_default(): void
    {
        self::assertSame([], $this->gatherAnalyserErrors([dirname(__DIR__, 3).'/core/tests/Phpstan/Fixtures/KernelTableWriteMigration.php.inc']));
    }

    public function test_it_reports_a_migration_in_the_global_namespace_when_the_core_modules_directory_does_not_exist(): void
    {
        $this->coreDirectory = __DIR__.'/Fixtures/NoSuchDirectory';

        self::assertSame(['12 cboxCms.kernelTableWrite', '13 cboxCms.kernelTableWrite'], $this->reported('KernelTableWriteMigration'));
    }

    public function test_it_reports_a_migration_in_a_directory_whose_name_only_starts_like_the_core_modules(): void
    {
        $root = sys_get_temp_dir().'/cms-kernel-table-write-'.bin2hex(random_bytes(4));
        $migration = $root.'/core-extras/seed.php';

        self::assertTrue(mkdir($root.'/core', 0o755, true) && mkdir($root.'/core-extras', 0o755));
        self::assertTrue(copy(self::fixture('KernelTableWriteMigration'), $migration));
        $this->coreDirectory = $root.'/core';

        try {
            $reported = array_map(static fn (Error $error): string => sprintf('%d %s', $error->getLine() ?? 0, $error->getIdentifier() ?? ''), $this->gatherAnalyserErrors([$migration]));
        } finally {
            unlink($migration);
            rmdir($root.'/core-extras');
            rmdir($root.'/core');
            rmdir($root);
        }

        self::assertSame(['12 cboxCms.kernelTableWrite', '13 cboxCms.kernelTableWrite'], $reported);
    }

    public function test_it_reports_every_write_to_the_credential_store_outside_the_identity_module(): void
    {
        // Not reported: reads and tables of the same name in other schemas (lines 36 to 40), the
        // identity module (lines 64 and 65).
        $this->analyse([self::fixture('CredentialTableWrites')], [
            [$this->credentialMessage('cms_identity.local_accounts'), 20],
            [$this->credentialMessage('cms_identity.local_accounts'), 21],
            [$this->credentialMessage('cms_identity.password_reset_tokens'), 22],
            [$this->credentialMessage('cms_identity.audit_copies'), 23],
            [$this->credentialMessage('cms_identity.local_accounts'), 28],
            [$this->credentialMessage('cms_identity.password_reset_tokens'), 29],
            [$this->credentialMessage('cms_identity.password_reset_tokens'), 30],
            [$this->message('actors'), 31],
            [$this->credentialMessage('cms_identity.local_accounts'), 31],
            [$this->credentialMessage('cms_identity.local_accounts'), 52],
            [$this->credentialMessage('cms_identity.local_accounts'), 77],
        ]);
    }

    public function test_it_reports_a_write_to_the_credential_store_as_non_ignorable(): void
    {
        self::assertSame([
            '20 cboxCms.kernelTableWrite',
            '21 cboxCms.kernelTableWrite',
            '22 cboxCms.kernelTableWrite',
            '23 cboxCms.kernelTableWrite',
            '28 cboxCms.kernelTableWrite',
            '29 cboxCms.kernelTableWrite',
            '30 cboxCms.kernelTableWrite',
            '31 cboxCms.kernelTableWrite',
            '31 cboxCms.kernelTableWrite',
            '52 cboxCms.kernelTableWrite',
            '77 cboxCms.kernelTableWrite',
        ], $this->reported('CredentialTableWrites'));
    }

    public function test_it_reads_the_credential_store_tables_a_statement_writes(): void
    {
        self::assertSame(['cms_identity.local_accounts'], KernelTableWriteRule::credentialTablesWrittenBy('UPDATE "cms_identity"."LOCAL_ACCOUNTS" SET version = 2'));
        self::assertSame(['cms_identity.password_reset_tokens'], KernelTableWriteRule::credentialTablesWrittenBy('delete from password_reset_tokens'));
        self::assertSame([], KernelTableWriteRule::credentialTablesWrittenBy('select * from cms_identity.local_accounts'));
        self::assertSame([], KernelTableWriteRule::credentialTablesWrittenBy('insert into public.local_accounts values (1)'));
        self::assertSame([], KernelTableWriteRule::tablesWrittenBy('insert into cms_identity.local_accounts values (1)'));
        self::assertSame('cms_identity.sessions', CredentialTables::of(' cms_identity . sessions '));
        self::assertNull(CredentialTables::of('sessions'));
        self::assertNull(CredentialTables::of('cms_identity.'));
        self::assertSame(CredentialStore::SCHEMA, CredentialTables::SCHEMA);
        self::assertSame(CredentialStore::TABLES, CredentialTables::TABLES);
    }

    public function test_it_reports_a_migration_writing_the_credential_store_outside_the_identity_modules_directory(): void
    {
        self::assertSame(['12 cboxCms.kernelTableWrite', '13 cboxCms.kernelTableWrite'], $this->reported('CredentialTableWriteMigration'));
    }

    public function test_it_allows_a_migration_writing_the_credential_store_in_the_identity_modules_directory(): void
    {
        $this->identityDirectory = __DIR__.'/Fixtures';

        self::assertSame([], $this->reported('CredentialTableWriteMigration'));
    }

    public function test_it_reports_the_identity_modules_migration_writing_a_kernel_table(): void
    {
        $this->identityDirectory = __DIR__.'/Fixtures';

        self::assertSame(['12 cboxCms.kernelTableWrite', '13 cboxCms.kernelTableWrite'], $this->reported('KernelTableWriteMigration'));
    }

    private function credentialMessage(string $table): string
    {
        return sprintf(
            'Write to the credential store table %s outside the identity module. Only %s writes the credentials of the local accounts, on the identity role\'s connection (PRD 5.16).',
            $table,
            KernelTableWriteRule::IDENTITY,
        );
    }

    private function message(string $table): string
    {
        return sprintf(
            'Write to the kernel table %s outside the kernel. Only the kernel writes its tables, through its commands (PRD 6.5 invariants 1 and 13, 11.12); a test creates what it needs through the testkit\'s fixture writers in %s.',
            $table,
            KernelTableWriteRule::FIXTURE_WRITERS,
        );
    }

    /**
     * @return Rule<CallLike>
     */
    protected function getRule(): Rule
    {
        return new KernelTableWriteRule(self::createReflectionProvider(), $this->coreDirectory, $this->identityDirectory);
    }
}
