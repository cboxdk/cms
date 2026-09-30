<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A value in the form of a core `datetime` field: an instant from $min to $max, each optional and
 * written in RFC 3339 with its offset, such as `2026-01-01T00:00:00Z`. cms:generate checks that each
 * is a real instant.
 */
#[Experimental]
final readonly class DatetimeShape implements FieldShape
{
    public function __construct(
        public ?string $min = null,
        public ?string $max = null,
    ) {}

    #[Override]
    public function base(): FieldBase
    {
        return FieldBase::Datetime;
    }
}
