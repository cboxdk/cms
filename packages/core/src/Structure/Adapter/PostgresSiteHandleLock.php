<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteHandleRef;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of a site's handle (PRD 5.9, 11.14): version 1 when a site has the handle, and
 * null when it is free. site.register reads it as free, so the commit takes the advisory lock of an
 * aggregate read as absent first, and two registrations of one handle commit one after the other;
 * the second finds it taken and is version_conflict. It reads through the SiteDirectory's lookup,
 * inside the command transaction.
 */
#[Internal]
final readonly class PostgresSiteHandleLock implements VersionLock
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
        return SiteHandleRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof SiteHandleRef) {
            throw new InvalidArgumentException(sprintf('The site handle lock locks site handles, not "%s".', $aggregate->aggregateKey()));
        }

        $site = new PostgresSiteDirectory($this->connections, $this->connection)->named($aggregate->handle);

        return $site instanceof StoredSite ? AggregateVersion::first() : null;
    }
}
