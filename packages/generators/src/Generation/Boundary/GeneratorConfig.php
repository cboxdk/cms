<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads `cbox-cms.generators` into a GenerationTarget. The defaults in the package's
 * config/generators.php follow the application layout of PRD 11.12; the workbench points them at
 * workbench/. `roots` maps each owner to its schema directory below the root.
 */
#[Internal]
final readonly class GeneratorConfig
{
    public const string KEY = 'cbox-cms.generators';

    /**
     * @param  string  $basePath  the application's base path, used when `root` is null
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig
     */
    public static function read(Repository $config, string $basePath): GenerationTarget
    {
        $values = $config->get(self::KEY);

        if (! is_array($values)) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf('%s is missing. Is the generators service provider registered?', self::KEY));
        }

        $root = $values['root'] ?? null;
        $strings = [];
        $problems = [];

        foreach (['php_directory', 'php_namespace', 'typescript_directory'] as $key) {
            $value = $values[$key] ?? null;

            if (! is_string($value)) {
                $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf('%s.%s must be a string.', self::KEY, $key));

                continue;
            }

            $strings[$key] = $value;
        }

        if ($root !== null && ! is_string($root)) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf('%s.root must be an absolute path, or null for the application\'s base path.', self::KEY));
        }

        $base = is_string($root) ? rtrim($root, '/') : $basePath;
        $roots = self::roots($values['roots'] ?? null, $base, $problems);

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return new GenerationTarget(
            $base,
            $roots,
            $strings['php_directory'] ?? '',
            $strings['php_namespace'] ?? '',
            $strings['typescript_directory'] ?? '',
        );
    }

    /**
     * The schema roots of `roots`, a map from owner to directory below the root.
     *
     * @param  list<GenerationProblem>  $problems
     * @return list<SchemaRoot>
     */
    private static function roots(mixed $value, string $base, array &$problems): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                '%s.roots must map each owner to its schema directory below the root, such as [\'app\' => \'schema\'].',
                self::KEY,
            ));

            return [];
        }

        $roots = [];

        foreach ($value as $owner => $directory) {
            if (! is_string($directory)) {
                $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf('%s.roots.%s must be a directory below the root, such as "schema".', self::KEY, $owner));

                continue;
            }

            try {
                $roots[] = new SchemaRoot(new Owner((string) $owner), $base, $directory);
            } catch (GenerationFailed $failed) {
                array_push($problems, ...$failed->problems);
            }
        }

        return $roots;
    }
}
