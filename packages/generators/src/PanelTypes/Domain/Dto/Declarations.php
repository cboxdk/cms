<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The TypeScript declarations of a contract's JSON document: the name of the document's type, the
 * lines that declare it and the types it reaches, and the names they import from the SDK.
 */
#[Internal]
final readonly class Declarations
{
    /**
     * @param  list<string>  $lines
     * @param  list<string>  $imports
     */
    public function __construct(
        public string $name,
        public array $lines,
        public array $imports,
    ) {}
}
