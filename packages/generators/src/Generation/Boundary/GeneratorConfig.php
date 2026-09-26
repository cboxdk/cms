<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads `cms.generators` into a GenerationTarget. The defaults in the package's
 * config/generators.php follow the application layout of PRD 11.12; the workbench points them at
 * workbench/.
 */
#[Internal]
final readonly class GeneratorConfig
{
    public const string KEY = 'cms.generators';

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

        foreach (['schema', 'php_directory', 'php_namespace', 'typescript_directory'] as $key) {
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

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return new GenerationTarget(
            is_string($root) ? rtrim($root, '/') : $basePath,
            $strings['schema'] ?? '',
            $strings['php_directory'] ?? '',
            $strings['php_namespace'] ?? '',
            $strings['typescript_directory'] ?? '',
        );
    }
}
