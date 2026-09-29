<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A position in Postgres' write-ahead log, a pg_lsn in the form Postgres writes it: two groups of
 * 1 to 8 uppercase hex digits separated by a slash, such as "16/B374D848" (PRD 8.5). It is compared
 * exactly, so it is kept in that one form.
 */
#[Experimental]
final readonly class LogSequenceNumber
{
    private const string PATTERN = '/\A[0-9A-F]{1,8}\/[0-9A-F]{1,8}\z/';

    /**
     * @throws InvalidReceipt when $value is not a pg_lsn as Postgres writes it
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidReceipt::logSequenceNumber($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
