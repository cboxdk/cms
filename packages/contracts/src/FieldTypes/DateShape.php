<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A value in the form of a core `date` field: a calendar date from $min to $max, each optional and
 * written `YYYY-MM-DD`. cms:generate checks that each is a real date.
 */
#[Experimental]
final readonly class DateShape implements FieldShape
{
    public function __construct(
        public ?string $min = null,
        public ?string $max = null,
    ) {}

    #[Override]
    public function base(): FieldBase
    {
        return FieldBase::Date;
    }
}
