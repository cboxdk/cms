<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;

/**
 * Where cms:generate reads the schema and writes the generated code (PRD 11.12): the schema roots,
 * each a directory of blueprint files and its owner, the directories of the generated code, and
 * the directory of the type tables' migrations and their schema locks, which ends in
 * `migrations/cms`, so a wrong setting can never point it at the application's own migrations.
 * Every path but the root is relative to the root, uses forward slashes and has no "." or ".."
 * segment, so the generated code never names a machine-specific path.
 */
#[Internal]
final readonly class GenerationTarget
{
    private const string RELATIVE_PATH = '/\A[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z/';

    /** The last two segments of the migrations directory. */
    public const string MIGRATIONS_SUFFIX = 'migrations/cms';

    private const string NAMESPACE = '/\A[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)*\z/';

    /** @var non-empty-list<SchemaRoot> sorted by owner */
    public array $roots;

    /**
     * @param  string  $root  the absolute directory the other paths are relative to
     * @param  list<SchemaRoot>  $roots  the schema roots, each below $root and with its own owner
     * @param  string  $phpDirectory  where the PHP code goes, in the namespace $phpNamespace
     * @param  string  $typeScriptDirectory  where the TypeScript goes
     * @param  string  $migrationsDirectory  where the type tables' migrations and schema locks go,
     *                                       ending in migrations/cms
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig
     */
    public function __construct(
        public string $root,
        array $roots,
        public string $phpDirectory,
        public string $phpNamespace,
        public string $typeScriptDirectory,
        public string $migrationsDirectory = 'database/'.self::MIGRATIONS_SUFFIX,
    ) {
        $problems = [];

        if (! str_starts_with($root, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $root) !== 1) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf('cbox-cms.generators.root "%s" is not an absolute path.', $root));
        }

        $problems = [...$problems, ...$this->rootProblems($root, $roots)];

        foreach (['php_directory' => $phpDirectory, 'typescript_directory' => $typeScriptDirectory, 'migrations_directory' => $migrationsDirectory] as $key => $path) {
            if (preg_match(self::RELATIVE_PATH, $path) !== 1) {
                $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                    'cbox-cms.generators.%s "%s" is not a relative path below the root, such as "app/Cms/Generated". Use forward slashes and no "." or ".." segments.',
                    $key,
                    $path,
                ));
            }
        }

        if ($migrationsDirectory !== self::MIGRATIONS_SUFFIX && ! str_ends_with($migrationsDirectory, '/'.self::MIGRATIONS_SUFFIX)) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                'cbox-cms.generators.migrations_directory "%s" does not end in "%s", such as "database/migrations/cms". cms:generate removes the files there that it did not write.',
                $migrationsDirectory,
                self::MIGRATIONS_SUFFIX,
            ));
        }

        if (preg_match(self::NAMESPACE, $phpNamespace) !== 1) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                'cbox-cms.generators.php_namespace "%s" is not a PHP namespace such as "App\\Cms\\Generated".',
                $phpNamespace,
            ));
        }

        if ($roots === []) {
            throw GenerationFailed::with([...$problems, $this->noRoots()]);
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        usort($roots, static fn (SchemaRoot $a, SchemaRoot $b): int => strcmp($a->owner->value, $b->owner->value));
        $this->roots = $roots;
    }

    /**
     * A problem for a root below another base than the target's root, and for an owner with more
     * than one root.
     *
     * @param  list<SchemaRoot>  $roots
     * @return list<GenerationProblem>
     */
    private function rootProblems(string $root, array $roots): array
    {
        $problems = [];
        $owners = [];

        foreach ($roots as $schemaRoot) {
            if (rtrim($schemaRoot->base, '/\\') !== $root) {
                $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                    'The schema root %s of %s lies below %s, not below cbox-cms.generators.root %s.',
                    $schemaRoot->directory,
                    $schemaRoot->owner->value,
                    $schemaRoot->base,
                    $root,
                ));
            }

            if (isset($owners[$schemaRoot->owner->value])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                    '%s has more than one schema root. Give each owner one directory in cbox-cms.generators.roots.',
                    $schemaRoot->owner->value,
                ));
            }

            $owners[$schemaRoot->owner->value] = true;
        }

        return $problems;
    }

    private function noRoots(): GenerationProblem
    {
        return new GenerationProblem(GenerateErrorCode::InvalidConfig, 'cbox-cms.generators.roots names no schema root. Map each owner to its directory below the root, such as [\'app\' => \'schema\'].');
    }
}
