<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A value in the form of a core `text` field: a single line of at most $maxLength characters (1 to
 * 10000), at least $minLength, in the format given.
 */
#[Experimental]
final readonly class TextShape implements FieldShape
{
    /**
     * @throws InvalidFieldShape when a length is out of range
     */
    public function __construct(
        public ?int $minLength = null,
        public int $maxLength = 255,
        public TextFormat $format = TextFormat::Plain,
    ) {
        ShapeRules::range('a text shape', 'maximum length', $maxLength, 1, 10000);
        ShapeRules::range('a text shape', 'minimum length', $minLength, 0);
        ShapeRules::order('a text shape', 'length', $minLength, $maxLength);
    }

    #[Override]
    public function base(): FieldBase
    {
        return FieldBase::Text;
    }
}
