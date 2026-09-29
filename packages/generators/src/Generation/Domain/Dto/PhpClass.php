<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The source of one generated PHP class, interface or enum before it becomes a file: the classes
 * it imports, fully qualified, and its lines after the imports.
 */
#[Internal]
final readonly class PhpClass
{
    /**
     * @param  list<string>  $imports
     * @param  list<string>  $lines
     */
    public function __construct(
        public array $imports,
        public array $lines,
    ) {}
}
