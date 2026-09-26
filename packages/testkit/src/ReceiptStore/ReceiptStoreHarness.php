<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * What the shared ReceiptStoreContract suite runs against: one receipt store that several sessions
 * reach, each on its own connection. For the FakeReceiptStore a session is an object over shared
 * rows; for a database store it is an independent connection to the same database.
 */
#[Experimental]
interface ReceiptStoreHarness
{
    /**
     * A new session on its own connection, with no transaction open.
     */
    public function session(): ReceiptStoreSession;

    /**
     * Makes sure that no partition covers the changeset times from $from to $to, both inclusive,
     * so store() of a receipt whose changeset time is in the range throws PartitionMissing. A
     * harness for a database store removes every partition whose span overlaps the range, which
     * can leave the rest of those days or months uncovered too; the FakeReceiptStore leaves
     * exactly the range uncovered. The shared suite calls it before it writes in the range.
     */
    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void;
}
