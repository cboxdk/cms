<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\BatchVersionLock;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * The version lock of the variant aggregate (PRD 5.4, 6.2 phase 7), as the app role under the
 * call's actor context: the head's row in `variant_heads`, FOR SHARE for Share and FOR NO KEY
 * UPDATE for Update. Two revises of one variant lock it one after the other, and the second sees
 * the first's version at commit (invariant 11). It runs on the default connection, or the one
 * named, inside the command transaction.
 */
#[Internal]
final readonly class PostgresVariantVersionLock implements BatchVersionLock
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

    /** The heads of a run of variants, locked in the order of their aggregate keys. */
    public const string LOCK_ALL = <<<'SQL'
        select h.entry_id::text as entry_id, h.variant, h.version
        from variant_heads as h
        join unnest(?::uuid[], ?::text[]) as k(entry_id, variant) on h.entry_id = k.entry_id and h.variant = k.variant
        order by h.entry_id, h.variant collate "C"
        SQL;

    #[Override]
    public function lockAll(array $aggregates, LockStrength $strength): array
    {
        $versions = [];
        $entries = [];
        $variants = [];

        foreach ($aggregates as $aggregate) {
            if (! $aggregate instanceof VariantRef) {
                throw new InvalidArgumentException(sprintf('The variant version lock locks variants, not "%s".', $aggregate->aggregateKey()));
            }

            $versions[$aggregate->aggregateKey()] = null;
            $entries[] = $aggregate->entry->toString();
            $variants[] = '"'.$aggregate->variant->value.'"';
        }

        $rows = $this->connections->connection($this->connection)->select(
            self::LOCK_ALL.' '.RowVersion::clause($strength).' of h',
            ['{'.implode(',', $entries).'}', '{'.implode(',', $variants).'}'],
            false,
        );

        foreach ($rows as $row) {
            if (! is_object($row)) {
                throw new UnexpectedValueException(sprintf('A locked row is an object, got %s.', get_debug_type($row)));
            }

            $variant = new VariantRef(EntryId::fromString(RowVersion::text($row, 'entry_id')), VariantKey::fromString(RowVersion::text($row, 'variant')));
            $versions[$variant->aggregateKey()] = RowVersion::of($row->version ?? null, $variant);
        }

        return $versions;
    }
}
