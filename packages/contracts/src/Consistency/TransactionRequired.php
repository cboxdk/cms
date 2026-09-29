<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;

/**
 * ReceiptStore::store(), the event log's writer, the actor context, the commit of a changeset or
 * the read audit was called without an open transaction on the caller's connection, or the commit
 * position of a transaction or the position of a read was asked for without one. The receipt and
 * the events are written in the command transaction (PRD 6.2 phase 7, 7.3), the read audit in the
 * read transaction, and the actor context lives only as long as its transaction, so this is a bug in the caller, not a
 * result. Nothing was stored or set.
 */
#[Experimental]
final class TransactionRequired extends LogicException
{
    public static function forStore(): self
    {
        return new self(
            'ReceiptStore::store() runs inside the caller\'s command transaction, and the connection has none open. The receipt commits and rolls back with its changeset, and the transaction holds the lock that keeps one receipt per changeset. Nothing was stored.',
        );
    }

    public static function forPosition(): self
    {
        return new self(
            'The commit position is the xid8 of the caller\'s command transaction, and the connection has none open. Outside a transaction the statement would get an xid that no changeset carries.',
        );
    }

    public static function forAccessContext(): self
    {
        return new self(
            'The actor context is set with SET LOCAL inside the transaction of the command or read it is for, and the connection has none open. Outside a transaction it would end with the statement, or a pooler in transaction mode could hand it to another client (PRD 5.10). Nothing was set.',
        );
    }

    public static function forReadPosition(): self
    {
        return new self(
            'The position of a read is the xmin of the snapshot of the caller\'s read transaction, and the connection has none open. Outside a transaction each statement has a snapshot of its own, so no position holds for the whole read (PRD 8.4).',
        );
    }

    public static function forReadAudit(): self
    {
        return new self(
            'The read audit is written inside the read transaction whose fields it records, and the connection has none open. It commits with the read, under the actor context the read set (PRD 12.12). Nothing was written.',
        );
    }

    public static function forEvents(): self
    {
        return new self(
            'The event log writes events inside the caller\'s command transaction, and the connection has none open. An event commits and rolls back with the state it tells about (PRD 7.3). Nothing was written.',
        );
    }

    public static function forChangeset(): self
    {
        return new self(
            'A changeset is committed inside the caller\'s command transaction, and the connection has none open. Its record, mutations, audit, events and receipt commit together or not at all (PRD 6.2 phase 7). Nothing was written.',
        );
    }
}
