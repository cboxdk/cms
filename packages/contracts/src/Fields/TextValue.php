<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * Text in UTF-8, such as the value of a text, long text or select field. The empty string is a
 * value; it is not NullValue.
 */
#[Experimental]
final readonly class TextValue implements FieldValue
{
    public function __construct(public string $value)
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw InvalidFieldValue::notUtf8();
        }
    }

    #[Override]
    public function equals(FieldValue $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }
}
