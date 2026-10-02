<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Ids\GrantId;
use InvalidArgumentException;

/**
 * A mutation that breaks its invariants.
 */
#[Experimental]
final class InvalidMutation extends InvalidArgumentException
{
    public static function headDidNotMove(RevisionNumber $revision): self
    {
        return new self(sprintf('A head move goes to another revision, but both are revision %d.', $revision->value));
    }

    public static function emptyLocales(GrantId $grant): self
    {
        return new self(sprintf('The grant %s names an empty locale set; null is every locale.', $grant->toString()));
    }

    public static function repeatedLocale(GrantId $grant, Locale $locale): self
    {
        return new self(sprintf('The grant %s names the locale %s twice.', $grant->toString(), $locale->value));
    }
}
