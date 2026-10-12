<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUseRef;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of a site's use of a locale (PRD 5.9, 11.14): version 1 while a released
 * placement locale in the locale below the site's root is not withdrawn, and null when none is.
 * site.remove_locale reads it as absent, so the commit takes the advisory lock of an aggregate read
 * as absent first, after the site's row, which placement.create holds FOR SHARE until it commits;
 * a placement committed in the locale meanwhile is then found, and the removal is
 * version_conflict. It reads through SiteLocaleUse, inside the command transaction.
 */
#[Internal]
final readonly class PostgresSiteLocaleUseLock implements VersionLock
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function kind(): string
    {
        return SiteLocaleUseRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof SiteLocaleUseRef) {
            throw new InvalidArgumentException(sprintf('The site locale use lock locks a site\'s use of a locale, not "%s".', $aggregate->aggregateKey()));
        }

        $placements = new PostgresSiteLocaleUse($this->connections, $this->connection)->placements($aggregate->site, $aggregate->locale);

        return $placements > 0 ? AggregateVersion::first() : null;
    }
}
