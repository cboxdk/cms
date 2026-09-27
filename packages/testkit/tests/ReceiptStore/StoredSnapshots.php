<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Receipts\StoredReceipt;

/**
 * The receipts as they were stored, shared by the sessions of one broken store.
 */
final class StoredSnapshots
{
    /** @var array<string, StoredReceipt> */
    public array $receipts = [];
}
