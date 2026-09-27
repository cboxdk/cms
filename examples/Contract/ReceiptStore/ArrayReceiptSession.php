<?php

declare(strict_types=1);

namespace Examples\Contract\ReceiptStore;

use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession;
use Closure;
use LogicException;
use Throwable;

/**
 * One connection to the ArrayReceiptHarness, with the transaction control the command kernel has
 * in production. The store reads and writes rows through it and never begins or ends a
 * transaction.
 *
 * Without a transaction a write commits at once. Inside one, a write is checked against the row
 * this connection sees when it is made, and kept. The connection replays its writes over the
 * committed row on every read, and once more at commit, so other connections see them only then.
 * A commit whose replay throws ends the transaction and applies nothing. A write that throws
 * inside a transaction fails it, as a failed statement does on Postgres: until rollBack() the
 * connection takes nothing else.
 */
final class ArrayReceiptSession implements ReceiptStoreSession
{
    /** @var array<string, list<Closure(?StoredReceipt): ?StoredReceipt>>|null the writes of the open transaction by changeset id */
    private ?array $writes = null;

    private bool $failed = false;

    public function __construct(private readonly ArrayReceiptHarness $database) {}

    public function receipts(): ReceiptStore
    {
        return new ArrayReceiptStore($this, $this->database->clock);
    }

    public function begin(): void
    {
        if ($this->writes !== null) {
            throw new LogicException('A transaction is open already; nested transactions and savepoints are forbidden.');
        }

        $this->writes = [];
    }

    public function commit(): void
    {
        $writes = $this->writes ?? throw new LogicException('No transaction is open.');
        $this->refuseWhenFailed();
        $this->writes = null;
        $rows = [];

        foreach ($writes as $key => $rowWrites) {
            $rows[] = $this->replay($this->database->row(ChangesetId::fromString($key)), $rowWrites);
        }

        foreach ($rows as $row) {
            if ($row instanceof StoredReceipt) {
                $this->database->put($row);
            }
        }
    }

    public function rollBack(): void
    {
        if ($this->writes === null) {
            throw new LogicException('No transaction is open.');
        }

        $this->writes = null;
        $this->failed = false;
    }

    public function inTransaction(): bool
    {
        return $this->writes !== null;
    }

    /**
     * The row of the changeset as this connection sees it: committed, with its own writes.
     */
    public function row(ChangesetId $changesetId): ?StoredReceipt
    {
        $this->refuseWhenFailed();

        return $this->replay($this->database->row($changesetId), $this->writes[$changesetId->toString()] ?? []);
    }

    /**
     * Writes the row of the changeset: $write turns the row into the new row, or throws.
     *
     * @param  Closure(?StoredReceipt): ?StoredReceipt  $write
     */
    public function write(ChangesetId $changesetId, Closure $write): void
    {
        $row = $this->row($changesetId);

        try {
            $written = $write($row);

            if (! $row instanceof StoredReceipt && $written instanceof StoredReceipt) {
                $this->database->route($written);
            }
        } catch (Throwable $error) {
            $this->failed = $this->writes !== null;

            throw $error;
        }

        if ($this->writes !== null) {
            $this->writes[$changesetId->toString()][] = $write;
        } elseif ($written instanceof StoredReceipt) {
            $this->database->put($written);
        }
    }

    /**
     * @param  list<Closure(?StoredReceipt): ?StoredReceipt>  $writes
     */
    private function replay(?StoredReceipt $row, array $writes): ?StoredReceipt
    {
        foreach ($writes as $write) {
            $row = $write($row);
        }

        return $row;
    }

    private function refuseWhenFailed(): void
    {
        if ($this->failed) {
            throw new LogicException('The transaction failed and takes nothing but a rollback.');
        }
    }
}
