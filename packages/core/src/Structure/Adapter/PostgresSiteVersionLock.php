<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * The version lock of the site aggregate (PRD 5.9, 6.2 phase 7): the site's row in `sites`, FOR
 * SHARE for Share and FOR NO KEY UPDATE for Update, through `cms_structure_lock_site`, because the
 * app role only reads sites and a row lock needs more. A placement created on a site waits for a
 * change of the site, such as its locales, and is checked against the site's version after it. It
 * runs on the default connection, or the one named, inside the command transaction.
 */
#[Internal]
final readonly class PostgresSiteVersionLock implements VersionLock
{
    public const string KIND = 'site';

    public const string LOCK = 'select cms_structure_lock_site(?::uuid, ?::boolean) as version';

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
        return self::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof SiteId) {
            throw new InvalidArgumentException(sprintf('The site version lock locks sites, not "%s".', $aggregate->aggregateKey()));
        }

        $version = $this->connections->connection($this->connection)->scalar(self::LOCK, [$aggregate->toString(), $strength === LockStrength::Update], false);

        if ($version === null) {
            return null;
        }

        if (! is_int($version)) {
            throw new UnexpectedValueException(sprintf('The version of a site is an integer, got %s.', get_debug_type($version)));
        }

        return new AggregateVersion($version);
    }
}
