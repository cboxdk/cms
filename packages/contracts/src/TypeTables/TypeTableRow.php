<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * One row of a type table as the TypeTableReader reads it (PRD 11.6): the entry and its fields as
 * the kernel's generic field values, the owner's and each extender's, which the record factory of
 * the type turns into its record. A field whose column is null holds a NullValue; an encrypted
 * field is left out, because the reader holds no key to its ciphertext (PRD 12.2).
 */
#[Experimental]
final readonly class TypeTableRow
{
    public function __construct(
        public EntryId $entry,
        public FieldValues $fields,
    ) {}
}
