<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Structure\Domain\NodeRouteRef;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of a route of a site in one language (PRD 5.9): version 1 when a node has the
 * route, and null when it is free. node.set_route reads it as free, so the commit takes the
 * advisory lock of an aggregate read as absent first, and two commands that claim one route commit
 * one after the other; the second finds it taken and is version_conflict. It reads through the
 * node reader's owner lookup, inside the command transaction.
 */
#[Internal]
final readonly class PostgresNodeRouteLock implements VersionLock
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
        return NodeRouteRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof NodeRouteRef) {
            throw new InvalidArgumentException(sprintf('The node route lock locks routes of a node, not "%s".', $aggregate->aggregateKey()));
        }

        $holder = new PostgresNodeReader($this->connections, $this->connection)
            ->routeHolder($aggregate->site, $aggregate->locale, $aggregate->route);

        return $holder instanceof NodeId ? AggregateVersion::first() : null;
    }
}
