<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * Where cms:generate reads the schema and writes the generated code (PRD 11.12). Every path but
 * the root is relative to the root, uses forward slashes and has no "." or ".." segment, so the
 * generated code never names a machine-specific path.
 */
#[Internal]
final readonly class GenerationTarget
{
    private const string RELATIVE_PATH = '/\A[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z/';

    private const string NAMESPACE = '/\A[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)*\z/';

    /**
     * @param  string  $root  the absolute directory the other paths are relative to
     * @param  string  $schema  the schema file
     * @param  string  $phpDirectory  where the PHP code goes, in the namespace $phpNamespace
     * @param  string  $typeScriptDirectory  where the TypeScript goes
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig
     */
    public function __construct(
        public string $root,
        public string $schema,
        public string $phpDirectory,
        public string $phpNamespace,
        public string $typeScriptDirectory,
    ) {
        $problems = [];

        if (! str_starts_with($root, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $root) !== 1) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf('cms.generators.root "%s" is not an absolute path.', $root));
        }

        foreach (['schema' => $schema, 'php_directory' => $phpDirectory, 'typescript_directory' => $typeScriptDirectory] as $key => $path) {
            if (preg_match(self::RELATIVE_PATH, $path) !== 1) {
                $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                    'cms.generators.%s "%s" is not a relative path below the root, such as "app/Cms/Generated". Use forward slashes and no "." or ".." segments.',
                    $key,
                    $path,
                ));
            }
        }

        if (preg_match(self::NAMESPACE, $phpNamespace) !== 1) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                'cms.generators.php_namespace "%s" is not a PHP namespace such as "App\\Cms\\Generated".',
                $phpNamespace,
            ));
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }
    }
}
