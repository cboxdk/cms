<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * A directory of blueprint files and their owner (PRD 11.12). The directory is relative to a base,
 * usually the application's base path, so a problem names a file the way a person finds it:
 * `schema/article.yaml` for the application, `vendor/acme/shop/schema/product.yaml` for an addon.
 */
#[Internal]
final readonly class SchemaRoot
{
    private const string RELATIVE_PATH = '/\A[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z/';

    /**
     * @param  string  $base  an absolute directory
     * @param  string  $directory  the schema directory below the base, such as "schema"
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig
     */
    public function __construct(
        public Owner $owner,
        public string $base,
        public string $directory,
    ) {
        $problems = [];

        if (! str_starts_with($base, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $base) !== 1) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf('The base "%s" of the schema root of %s is not an absolute path.', $base, $owner->value));
        }

        if (preg_match(self::RELATIVE_PATH, $directory) !== 1) {
            $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                'The schema root "%s" of %s is not a relative path below its base, such as "schema". Use forward slashes and no "." or ".." segments.',
                $directory,
                $owner->value,
            ));
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }
    }

    /**
     * The absolute path of the directory.
     */
    public function path(): string
    {
        return rtrim($this->base, '/\\').'/'.$this->directory;
    }

    /**
     * A file below the root as a problem names it: the root's directory and the file's path below it.
     */
    public function file(string $relativePath): string
    {
        return $this->directory.'/'.$relativePath;
    }
}
