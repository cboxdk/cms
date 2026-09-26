<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;

/**
 * What cms:schema:editor did to the blueprint files. Files are named as SchemaFile::$file names
 * them and sorted.
 */
#[Internal]
final readonly class EditorReport
{
    /**
     * @param  list<string>  $changed  files whose editor line was added or corrected
     * @param  list<string>  $unchanged  files that already had the right editor line
     * @param  list<GenerationProblem>  $problems  files that could not be read or written, sorted by code, then message
     */
    public function __construct(
        public array $changed,
        public array $unchanged,
        public array $problems,
    ) {}
}
