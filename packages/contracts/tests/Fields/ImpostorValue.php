<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fields;

use Cbox\Cms\Contracts\Fields\FieldValue;
use Override;

/**
 * A field value of another kind that holds the same PHP value as a kernel value, so a test can
 * show that equals() compares the kind and not only the value.
 */
final readonly class ImpostorValue implements FieldValue
{
    public function __construct(
        public int|bool|string $value,
    ) {}

    #[Override]
    public function equals(FieldValue $other): bool
    {
        return false;
    }
}
