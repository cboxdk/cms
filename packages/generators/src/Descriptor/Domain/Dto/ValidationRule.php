<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;

/**
 * One rule of a field's runtime validator (PRD 11.12): its name and its arguments, each a string
 * as the blueprint file writes the value, such as `255` or `2026-01-01`.
 */
#[Internal]
final readonly class ValidationRule
{
    /**
     * @param  list<string>  $arguments
     */
    public function __construct(
        public ValidationRuleName $name,
        public array $arguments = [],
    ) {}
}
