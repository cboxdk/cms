<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Migrations\Domain\MigrationSource;
use Cbox\Cms\Generators\Migrations\Domain\SchemaLocks;
use Cbox\Cms\Generators\Migrations\Domain\TableChanges;
use Closure;
use Override;

/**
 * The migrations of the type tables (PRD 4.1, 4.2, 11.6, 11.12), in the migrations directory,
 * `database/migrations/cms` in an application.
 *
 * It reads the committed schema locks there (SchemaLocks), computes the next lock of every type
 * with TableChanges, which refuses every change but a new type and new optional fields until
 * schema evolution (B3), and writes each lock as `<table>.lock` and a migration per step of it
 * (MigrationSource). So the output is a function of the compiled schema and the committed locks:
 * a run that finds nothing new writes the same bytes, a new type adds a lock and its create
 * migration, and a new optional field adds a step and its add_columns migration. The lock's text
 * comes from the closure it is given, the Boundary's TypeTableLockJson::encode() in the container,
 * which also reads the locks back, so one Boundary owns the lock's JSON (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class TypeTableMigrations implements Generator
{
    /**
     * What the type table holds for each core field type of the blueprint schema v1. The column's
     * Postgres type and checks come from the field type's own descriptor; a field type without a
     * mapping here is refused as invalid output. The generator-coverage test holds the keys to the
     * field types of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array FIELD_TYPES = [
        'boolean' => 'a boolean column',
        'date' => 'a date column',
        'datetime' => 'a timestamptz column',
        'decimal' => 'a numeric column of its precision and scale',
        'group' => 'a jsonb column',
        'integer' => 'a bigint column',
        'long_text' => 'a text column',
        'rich_text' => 'a jsonb column of Portable Text',
        'select' => 'a text column, or a text array for a multiple select, checked against the options',
        'text' => 'a text column',
    ];

    /**
     * What the migrations hold for a blueprint file of each kind. The generator-coverage test holds
     * the keys to the kinds of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array KINDS = [
        'extension' => 'its fields as the nullable columns ext__<namespace>__<handle> of the table of the type it extends',
        'type' => 'a type table <owner>__<handle> with a schema lock and a migration per step of it',
    ];

    /**
     * @param  Closure(TypeTableLock): string  $lockText  gives the text of a lock file
     */
    public function __construct(
        private SchemaLocks $locks,
        private Closure $lockText,
    ) {}

    #[Override]
    public function directory(GenerationTarget $target): string
    {
        return $target->migrationsDirectory;
    }

    #[Override]
    public function generate(CompiledSchema $schema, GenerationTarget $target): array
    {
        foreach ($schema->types as $type) {
            foreach ($type->fields as $field) {
                $this->assertMapped($field);
            }
        }

        $files = [];

        foreach (TableChanges::next($schema, $this->locks->read($target->root, $target->migrationsDirectory)) as $lock) {
            $files = [...$files, ...$this->files($lock, $target->migrationsDirectory)];
        }

        return $files;
    }

    /**
     * @return list<GeneratedFile>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function files(TypeTableLock $lock, string $directory): array
    {
        $files = [new GeneratedFile($directory.'/'.$lock->file(), ($this->lockText)($lock))];

        foreach (range(1, $lock->steps) as $step) {
            $files[] = new GeneratedFile($directory.'/'.MigrationSource::name($lock, $step).'.php', MigrationSource::source($lock, $step));
        }

        return $files;
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput when FIELD_TYPES lacks the
     *                          field's type
     */
    private function assertMapped(FieldDescriptor $field): void
    {
        GeneratedLines::fieldType(self::class, self::FIELD_TYPES, $field);
    }
}
