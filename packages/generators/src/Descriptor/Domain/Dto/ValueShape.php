<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;

/**
 * What a field type makes of a value outside the database (PRD 11.12): its PHP type and its
 * TypeScript type, both without null, the rules of its runtime validator besides `required` and
 * `nullable`, and the choices of a field with a fixed list.
 */
#[Internal]
final readonly class ValueShape
{
    /**
     * @param  list<ValidationRule>  $rules
     * @param  list<SelectOption>  $choices  in the order of the file
     */
    public function __construct(
        public PhpType $php,
        public TypeScriptType $typeScript,
        public array $rules,
        public array $choices = [],
    ) {}
}
