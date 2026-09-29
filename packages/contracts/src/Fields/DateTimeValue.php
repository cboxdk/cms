<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use DateTimeZone;
use Override;

/**
 * An instant, as a datetime field holds it, in UTC with microseconds. An instant given in another
 * time zone is converted, so two values of the same instant are equal.
 */
#[Experimental]
final readonly class DateTimeValue implements FieldValue
{
    public DateTimeImmutable $value;

    public function __construct(DateTimeImmutable $value)
    {
        $this->value = $value->setTimezone(new DateTimeZone('UTC'));
    }

    #[Override]
    public function equals(FieldValue $other): bool
    {
        return $other instanceof self && $other->value->format('U.u') === $this->value->format('U.u');
    }
}
