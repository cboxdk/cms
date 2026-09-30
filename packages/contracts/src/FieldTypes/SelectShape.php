<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A value in the form of a core `select` field: one of 1 to 500 choices, in the order given, or with
 * $multiple a list of them, of $minItems to $maxItems items (1 to 500), which only a multiple select
 * has. A multiple select is never filterable or sortable. Two choices with one value are refused.
 */
#[Experimental]
final readonly class SelectShape implements FieldShape
{
    /** @var list<SelectChoice> */
    public array $choices;

    /**
     * @param  list<SelectChoice>  $choices
     *
     * @throws InvalidFieldShape when the choices or the item counts break the rules above
     */
    public function __construct(
        array $choices,
        public bool $multiple = false,
        public ?int $minItems = null,
        public ?int $maxItems = null,
    ) {
        if ($choices === [] || count($choices) > 500) {
            throw InvalidFieldShape::because(sprintf('A select shape has 1 to 500 choices, got %d.', count($choices)));
        }

        $values = array_map(static fn (SelectChoice $choice): string => $choice->value, $choices);

        if (count(array_unique($values)) !== count($values)) {
            throw InvalidFieldShape::because(sprintf('A select shape has each value once, got %s.', implode(', ', $values)));
        }

        if (! $multiple && ($minItems !== null || $maxItems !== null)) {
            throw InvalidFieldShape::because('Only a multiple select shape has a minimum or maximum number of items.');
        }

        ShapeRules::range('a select shape', 'minimum number of items', $minItems, 0);
        ShapeRules::range('a select shape', 'maximum number of items', $maxItems, 1, 500);
        ShapeRules::order('a select shape', 'number of items', $minItems, $maxItems);
        $this->choices = $choices;
    }

    #[Override]
    public function base(): FieldBase
    {
        return FieldBase::Select;
    }
}
