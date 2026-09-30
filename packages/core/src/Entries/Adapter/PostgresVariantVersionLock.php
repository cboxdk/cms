<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * The version lock of the variant aggregate (PRD 5.4, 6.2 phase 7), as the app role under the
 * call's actor context: the head's row in `variant_heads`, FOR SHARE for Share and FOR NO KEY
 * UPDATE for Update. Two revises of one variant lock it one after the other, and the second sees
 * the first's version at commit (invariant 11). It runs on the default connection, or the one
 * named, inside the command transaction.
 */
#[Internal]
final readonly class PostgresVariantVersionLock implements VersionLock
{
    public const string KIND = 'variant';

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
        if (! $aggregate instanceof VariantRef) {
            throw new InvalidArgumentException(sprintf('The variant version lock locks variants, not "%s".', $aggregate->aggregateKey()));
        }

        return RowVersion::of($this->connections->connection($this->connection)
            ->table('variant_heads')
            ->where('entry_id', $aggregate->entry->toString())
            ->where('variant', $aggregate->variant->value)
            ->lock(RowVersion::clause($strength))
            ->useWritePdo()
            ->value('version'), $aggregate);
    }
}
