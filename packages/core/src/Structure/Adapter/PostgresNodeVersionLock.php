<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * The version lock of the node aggregate (PRD 5.8, 6.2 phase 7), as the app role under the call's
 * actor context: the node's row in `nodes`, FOR SHARE for Share and FOR NO KEY UPDATE for Update,
 * so an entry created below a node waits for a change of the node, such as a move, and is checked
 * against the node's version after it. It runs on the default connection, or the one named, inside
 * the command transaction.
 */
#[Internal]
final readonly class PostgresNodeVersionLock implements VersionLock
{
    public const string KIND = 'node';

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
        if (! $aggregate instanceof NodeId) {
            throw new InvalidArgumentException(sprintf('The node version lock locks nodes, not "%s".', $aggregate->aggregateKey()));
        }

        $version = $this->connections->connection($this->connection)
            ->table('nodes')
            ->where('id', $aggregate->toString())
            ->lock($strength === LockStrength::Update ? 'for no key update' : 'for share')
            ->useWritePdo()
            ->value('version');

        if ($version === null) {
            return null;
        }

        if (! is_int($version)) {
            throw new UnexpectedValueException(sprintf('The version of a node is an integer, got %s.', get_debug_type($version)));
        }

        return new AggregateVersion($version);
    }
}
