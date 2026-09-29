<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One field of a FieldMap: its handle and its value.
 */
#[Experimental]
final readonly class NamedValue
{
    public function __construct(
        public FieldHandle $handle,
        public FieldValue $value,
    ) {}
}
