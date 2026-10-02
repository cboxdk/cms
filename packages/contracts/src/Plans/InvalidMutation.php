<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Ids\SiteId;
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

    public static function repeatedPermission(RoleId $role, CommandName $permission): self
    {
        return new self(sprintf('The role %s names the permission %s twice.', $role->toString(), $permission->value));
    }

    public static function siteHandle(SiteId $site, string $handle): self
    {
        return new self(sprintf(
            'The site %s has the handle "%s"; a site\'s handle is a lower-case letter followed by at most 62 lower-case letters, digits or underscores.',
            $site->toString(),
            mb_substr($handle, 0, 80),
        ));
    }

    public static function siteWithoutLocales(SiteId $site): self
    {
        return new self(sprintf('The site %s is registered with no locale; a site publishes in at least one.', $site->toString()));
    }

    public static function repeatedSiteLocale(SiteId $site, Locale $locale): self
    {
        return new self(sprintf('The site %s names the locale %s twice.', $site->toString(), $locale->value));
    }
}
