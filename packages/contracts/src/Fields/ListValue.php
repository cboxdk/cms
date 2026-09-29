<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * An ordered list of values, such as the options chosen in a select field that allows several.
 * The order is part of the value.
 */
#[Experimental]
final readonly class ListValue implements FieldValue
{
    /** @var list<FieldValue> */
    public array $items;

    public function __construct(FieldValue ...$items)
    {
        $this->items = array_values($items);
    }

    #[Override]
    public function equals(FieldValue $other): bool
    {
        if (! $other instanceof self || count($other->items) !== count($this->items)) {
            return false;
        }

        return array_all($this->items, fn (FieldValue $item, int $index): bool => $item->equals($other->items[$index]));
    }
}
