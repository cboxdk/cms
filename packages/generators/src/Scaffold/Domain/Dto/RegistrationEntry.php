<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What a contribution adds to the registration in index.ts: its key and the expression its kind
 * takes, a lazy import of a component's module or the check's function, and for a function the
 * import that brings it.
 */
#[Internal]
final readonly class RegistrationEntry
{
    public function __construct(
        public string $id,
        public string $expression,
        public ?string $import = null,
    ) {}
}
