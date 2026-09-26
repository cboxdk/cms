<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\IdempotencyStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\InvalidIdempotencyValue;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use LogicException;

/**
 * A live record of `idempotency_keys` as the lookup reads it: the content hash and the changeset.
 *
 * The store writes only values it can read back, so a value the domain refuses is a bug or a write
 * from outside the store, and throws a LogicException that names the column.
 */
#[Internal]
final readonly class IdempotencyRecord
{
    private function __construct(
        public ContentHash $hash,
        public ChangesetId $changesetId,
    ) {}

    /**
     * @param  object  $row  a row with the text columns content_hash and changeset_id
     */
    public static function fromRow(object $row): self
    {
        try {
            return new self(
                new ContentHash(self::text($row, 'content_hash')),
                ChangesetId::fromString(self::text($row, 'changeset_id')),
            );
        } catch (InvalidIdempotencyValue|InvalidUuid7 $invalid) {
            throw new LogicException(sprintf('A row of %s does not make a valid record: %s', PostgresIdempotencyStore::TABLE, $invalid->getMessage()), 0, $invalid);
        }
    }

    private static function text(object $row, string $column): string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return is_string($value)
            ? $value
            : throw new LogicException(sprintf('The column %s.%s is %s, expected text.', PostgresIdempotencyStore::TABLE, $column, get_debug_type($value)));
    }
}
