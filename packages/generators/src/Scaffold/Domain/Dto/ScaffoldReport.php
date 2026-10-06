<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What a scaffold changed below an addon's package: the files it wrote, the files it kept because
 * the addon already had them, each relative to the package and sorted, and its notes.
 */
#[Internal]
final readonly class ScaffoldReport
{
    /**
     * @param  list<string>  $written
     * @param  list<string>  $kept
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $written,
        public array $kept,
        public array $notes = [],
    ) {}
}
