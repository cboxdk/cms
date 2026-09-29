<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The highest data classification a principal may read (PRD 12.2). The classes are ordered from
 * public to sensitive, and access to one class includes every class below it. The read pipeline
 * removes every field classified above the access of the read's AccessContext (PRD 6.2).
 */
#[Experimental]
enum ClassificationAccess: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Personal = 'personal';
    case Sensitive = 'sensitive';

    /**
     * The place of the class in the order, 0 for public to 4 for sensitive.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Internal => 1,
            self::Confidential => 2,
            self::Personal => 3,
            self::Sensitive => 4,
        };
    }

    /**
     * Whether a principal with this access may read a field classified $classification.
     */
    public function allows(self $classification): bool
    {
        return $classification->rank() <= $this->rank();
    }

    /**
     * The lower of this access and $other.
     */
    public function atMost(self $other): self
    {
        return $other->rank() < $this->rank() ? $other : $this;
    }
}
