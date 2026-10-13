<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;

/**
 * The field a condition reads (gap G3): a sibling of the conditional field, or a sibling of one of
 * the groups it is in, within the same set of fields, the owner's or one extender's. `up` counts
 * the groups to go up from the object that holds the conditional field: 0 is a sibling, 1 a
 * sibling of its group, and so on. An item of a repeated group is one object, so its fields read
 * their siblings in the same item.
 */
#[Experimental]
final readonly class FieldReference
{
    /** The most groups a reference goes up. */
    public const int MAX_UP = 10;

    /**
     * @throws InvalidRules when up is below 0 or above MAX_UP
     */
    public function __construct(
        public int $up,
        public FieldHandle $handle,
    ) {
        if ($up < 0 || $up > self::MAX_UP) {
            throw InvalidRules::reference($this->toString());
        }
    }

    /**
     * A reference to a sibling of the conditional field.
     */
    public static function sibling(string $handle): self
    {
        return new self(0, new FieldHandle($handle));
    }

    /**
     * The reference as a blueprint writes it: `../` once per group up, then the handle.
     */
    public function toString(): string
    {
        return str_repeat('../', max(0, $this->up)).$this->handle->value;
    }
}
