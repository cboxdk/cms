<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Everything one run of cms:generate produced: the files, sorted by path, and the directories the
 * generators own, sorted. A file in an owned directory that is not in the list is stale.
 */
#[Internal]
final readonly class GenerationResult
{
    /**
     * @param  list<GeneratedFile>  $files  sorted by path, each path once
     * @param  list<string>  $directories  sorted, each once
     */
    public function __construct(
        public array $files,
        public array $directories,
    ) {}

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(static fn (GeneratedFile $file): string => $file->path, $this->files);
    }
}
