<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * One file a generator produced: its path relative to the generation root and its exact bytes.
 * The contents end with one newline and have Unix line endings, like the formatters want.
 */
#[Internal]
final readonly class GeneratedFile
{
    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public function __construct(
        public string $path,
        public string $contents,
    ) {
        $segments = explode('/', $path);

        if ($path === '' || str_starts_with($path, '/') || array_intersect($segments, ['', '.', '..']) !== [] || str_contains($path, '\\')) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                'Generated file path "%s" is not a relative path with forward slashes and no empty, "." or ".." segments.',
                $path,
            ));
        }

        if (! str_ends_with($contents, "\n") || str_ends_with($contents, "\n\n") || str_contains($contents, "\r")) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                'Generated file "%s" must end with exactly one newline and use Unix line endings.',
                $path,
            ));
        }
    }
}
