<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\KernelTables;
use Cbox\Cms\Testkit\Phpstan\KernelTableWriteRule;
use PhpParser\Node\Expr\CallLike;
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
        return new KernelTableWriteRule(self::createReflectionProvider(), $this->coreDirectory);
    }
}
