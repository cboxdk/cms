<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpSource;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;

/**
 * The migration of one step of a type table's schema lock (PRD 11.6, 11.12): a Laravel migration
 * that returns an anonymous class, run as the owner role like the core's.
 *
 * Its name is `<table>_<step>_create` for step 1 and `<table>_<step>_add_columns` for a later
 * step, with the step in four digits, so the steps of one table run in order, and every type table
 * migration sorts after the core's dated migrations, whose tables it references.
 *
 * - Step 1 runs in the migration's transaction: it creates the table and its indexes
 *   (TypeTableDdl::create()), narrows the grants of the app role to SELECT, INSERT, UPDATE and
 *   DELETE with the core's TablePrivileges, and enables and forces row level security with the
 *   policies of every type table through the core's TypeTableAccess (PRD 4.2, 5.10).
 * - A later step runs outside a transaction, because it builds its indexes concurrently, with a
 *   lock_timeout of 2 s, so an ALTER TABLE that waits behind a long query fails instead of
 *   blocking every query on the table behind it (PRD 4.2, 11.12). Running it again after a failure
 *   is safe: ADD COLUMN IF NOT EXISTS, and an invalid index is dropped before it is built.
 *
 * down() drops the table, or the step's columns and their indexes with them.
 *
 * The output is formatted the way Pint, Rector and PHPStan level 10 accept it unchanged.
 */
#[Internal]
final readonly class MigrationSource
{
    public const string LOCK_TIMEOUT = '2s';

    /** @var list<string> the app role's privileges on a type table */
    public const array PRIVILEGES = ['Select', 'Insert', 'Update', 'Delete'];

    private const int COMMENT_WIDTH = 96;

    public static function name(TypeTableLock $lock, int $step): string
    {
        return sprintf('%s_%04d_%s', $lock->table, $step, $step === 1 ? 'create' : 'add_columns');
    }

    public static function source(TypeTableLock $lock, int $step): string
    {
        return implode("\n", $step === 1 ? self::create($lock) : self::add($lock, $step))."\n";
    }

    /**
     * @return list<string>
     */
    private static function create(TypeTableLock $lock): array
    {
        $table = PhpSource::literal($lock->table);
        $privileges = implode(', ', array_map(static fn (string $case): string => 'TablePrivilege::'.$case, self::PRIVILEGES));

        return [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Cbox\Cms\Core\Access\Infrastructure\TypeTableAccess;',
            'use Cbox\Cms\Core\Database\Domain\TablePrivilege;',
            'use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;',
            'use Illuminate\Database\Migrations\Migration;',
            'use Illuminate\Support\Facades\DB;',
            '',
            ...self::comment($lock, 1, sprintf(
                'Creates the type table %s of the type %s (PRD 4.1, 11.6): its system columns and key, a column per top-level field, the foreign keys and the indexes. Then it narrows the app role\'s grants on it to SELECT, INSERT, UPDATE and DELETE, and enables and forces row level security with the policies of every type table (PRD 4.2, 5.10).',
                $lock->table,
                $lock->type->value,
            )),
            'return new class extends Migration',
            '{',
            '    public function up(): void',
            '    {',
            '        $connection = DB::connection($this->getConnection());',
            '',
            ...array_merge(...array_map(self::statement(...), TypeTableDdl::create($lock))),
            sprintf('        new TablePrivileges($connection)->limitTo(%s, [%s]);', $table, $privileges),
            sprintf('        new TypeTableAccess($connection)->protect(%s);', $table),
            '    }',
            '',
            '    public function down(): void',
            '    {',
            '        $connection = DB::connection($this->getConnection());',
            '',
            ...self::statement(TypeTableDdl::dropTable($lock)),
            '    }',
            '};',
        ];
    }

    /**
     * @return list<string>
     */
    private static function add(TypeTableLock $lock, int $step): array
    {
        $statements = [];

        foreach (TypeTableDdl::add($lock, $step) as $statement) {
            $statements = [...$statements, ...self::statement($statement, '    ')];
        }

        return [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Illuminate\Database\Migrations\Migration;',
            'use Illuminate\Support\Facades\DB;',
            '',
            ...self::comment($lock, $step, sprintf(
                'Adds the columns of the new optional fields of the type %s to its table %s (PRD 11.6), and builds their indexes concurrently. It runs outside a transaction with a lock_timeout of %s, so it fails instead of blocking the table behind a long query, and it can run again after a failure (PRD 4.2).',
                $lock->type->value,
                $lock->table,
                self::LOCK_TIMEOUT,
            )),
            'return new class extends Migration',
            '{',
            '    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction. */',
            '    public $withinTransaction = false;',
            '',
            '    public function up(): void',
            '    {',
            '        $connection = DB::connection($this->getConnection());',
            sprintf("        \$connection->statement(\"set lock_timeout = '%s'\");", self::LOCK_TIMEOUT),
            '',
            '        try {',
            ...$statements,
            '        } finally {',
            "            \$connection->statement('reset lock_timeout');",
            '        }',
            '    }',
            '',
            '    public function down(): void',
            '    {',
            '        $connection = DB::connection($this->getConnection());',
            '',
            ...self::statement(TypeTableDdl::dropColumns($lock, $step)),
            '    }',
            '};',
        ];
    }

    /**
     * @return list<string>
     */
    private static function comment(TypeTableLock $lock, int $step, string $summary): array
    {
        $paragraphs = [
            $summary,
            sprintf('Step %d of the schema lock %s next to it. Generated by cms:generate from the blueprint v1 files below the schema roots and the schema lock. Do not edit this file: change the blueprints and run cms:generate.', $step, $lock->file()),
        ];
        $lines = ['/*'];

        foreach ($paragraphs as $index => $paragraph) {
            if ($index > 0) {
                $lines[] = ' *';
            }

            foreach (explode("\n", wordwrap($paragraph, self::COMMENT_WIDTH)) as $line) {
                $lines[] = ' * '.$line;
            }
        }

        $lines[] = ' */';

        return $lines;
    }

    /**
     * `$connection->statement(<<<'SQL' ... SQL);` with the statement's lines indented below it.
     *
     * @return list<string>
     */
    private static function statement(string $sql, string $indent = ''): array
    {
        return [
            $indent.'        $connection->statement(<<<\'SQL\'',
            ...array_map(static fn (string $line): string => $indent.'            '.$line, explode("\n", $sql)),
            $indent.'            SQL);',
        ];
    }
}
