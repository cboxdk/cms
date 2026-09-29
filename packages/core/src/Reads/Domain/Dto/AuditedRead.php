<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Reads\Domain\InvalidQueryCall;

/**
 * The fields of one entry a read returned whose classification requires the read audit (PRD 12.2,
 * 12.12): the entry, the fields by the address code gives them (`<handle>` or
 * `ext.<namespace>.<handle>`), each once and sorted, and the highest classification among them.
 * It names the fields, never their values.
 */
#[Internal]
final readonly class AuditedRead
{
    /** @var non-empty-list<string> */
    public array $fields;

    /**
     * @param  list<string>  $fields
     *
     * @throws InvalidQueryCall when no field is given
     */
    public function __construct(
        public EntryId $entry,
        array $fields,
        public ClassificationAccess $classification,
    ) {
        $fields = array_values(array_unique($fields));
        sort($fields, SORT_STRING);

        if ($fields === []) {
            throw InvalidQueryCall::auditWithoutFields($entry);
        }

        $this->fields = $fields;
    }
}
