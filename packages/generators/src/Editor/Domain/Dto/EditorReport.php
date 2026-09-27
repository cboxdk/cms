<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;

/**
 * What cms:schema:editor did to the blueprint files. Files are named as SchemaFile::$file names
 * them and sorted. The schema roots it skipped, because they lie below vendor/, are named by their
 * directory below the base and sorted.
 */
#[Internal]
final readonly class EditorReport
{
    /**
     * @param  list<string>  $changed  files whose editor line was added or corrected
     * @param  list<string>  $unchanged  files that already had the right editor line
     * @param  list<GenerationProblem>  $problems  files that could not be read or written, sorted by code, then message
     * @param  list<string>  $skipped  schema roots below vendor/, whose files Composer installs and the command leaves alone, such as `vendor/acme/shop/schema`
     */
    public function __construct(
        public array $changed,
        public array $unchanged,
        public array $problems,
        public array $skipped,
    ) {}
}
