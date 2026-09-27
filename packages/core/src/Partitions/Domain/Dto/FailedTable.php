<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;

/**
 * A table of the partition policy that one run of partition maintenance could not manage, as the
 * run's report holds it: the values of the UnmanageableTable, without the exception and its stack.
 * The run went on with the other tables; this one needs an operator, and trying again does not
 * help until the table is fixed.
 */
#[Experimental]
final readonly class FailedTable
{
    /**
     * @param  string|null  $partition  the partition the table was refused at, or null when the table itself cannot be managed
     * @param  string  $message  the UnmanageableTable's message, which starts with [partition_table_unmanageable]
     * @param  string|null  $cause  the message of the error behind it, such as Postgres's refusal to attach, or null when there was none
     */
    public function __construct(
        public string $table,
        public ?string $partition,
        public string $message,
        public ?string $cause,
    ) {}

    public static function of(string $table, UnmanageableTable $refusal): self
    {
        return new self(
            $table,
            $refusal->partition,
            $refusal->getMessage(),
            $refusal->getPrevious()?->getMessage(),
        );
    }
}
