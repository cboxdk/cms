<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Override;

/**
 * A `select` field: one choice from a fixed list, or several when `multiple` is true (default
 * false). Only a multiple select has `min_items` and `max_items`.
 */
#[Internal]
final readonly class SelectOptions implements FieldOptions
{
    public const bool DEFAULT_MULTIPLE = false;

    /**
     * @param  list<SelectOption>  $options  in the order of the file
     */
    public function __construct(
        public array $options,
        public bool $multiple,
        public ?int $minItems,
        public ?int $maxItems,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return CoreFieldType::Select->value;
    }
}
