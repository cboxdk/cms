<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An option of a SelectShape: the value stored, a lowercase snake_case handle of at most 63
 * characters, and the label of 1 to 100 characters the panel shows. cms:generate checks the form of
 * the value.
 */
#[Experimental]
final readonly class SelectChoice
{
    /**
     * @throws InvalidFieldShape when the label is out of range
     */
    public function __construct(
        public string $value,
        public string $label,
    ) {
        ShapeRules::text('a select choice', 'label', $label, 100);
    }
}
