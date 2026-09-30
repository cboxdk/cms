<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A value in the form of a core `long_text` field: a text of at most $maxLength characters (1 to
 * 1000000), at least $minLength. A field of it is never filterable or sortable.
 */
#[Experimental]
final readonly class LongTextShape implements FieldShape
{
    /**
     * @throws InvalidFieldShape when a length is out of range
     */
    public function __construct(
        public ?int $minLength = null,
        public int $maxLength = 10000,
    ) {
        ShapeRules::range('a long text shape', 'maximum length', $maxLength, 1, 1000000);
        ShapeRules::range('a long text shape', 'minimum length', $minLength, 0);
        ShapeRules::order('a long text shape', 'length', $minLength, $maxLength);
    }

    #[Override]
    public function base(): FieldBase
    {
        return FieldBase::LongText;
    }
}
