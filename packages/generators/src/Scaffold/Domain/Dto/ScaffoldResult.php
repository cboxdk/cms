<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;

/**
 * What a scaffold writes below an addon's package: files that are written only where none exists,
 * because the addon's own code is never overwritten, files that are written anew, because the
 * scaffold owns them, and notes for the person: what to add to the manifest, and what to run next.
 */
#[Internal]
final readonly class ScaffoldResult
{
    /**
     * @param  list<GeneratedFile>  $files  written only where no file exists
     * @param  list<GeneratedFile>  $updates  written whatever exists
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $files = [],
        public array $updates = [],
        public array $notes = [],
    ) {}
}
