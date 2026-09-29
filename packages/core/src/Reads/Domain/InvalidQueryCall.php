<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use LogicException;

/**
 * A query action or its result broke the query pipeline's rules. It is a bug in the action or in
 * the kernel, not bad input, so the read ends with it and nothing is answered.
 */
#[Internal]
final class InvalidQueryCall extends LogicException
{
    /**
     * @param  class-string  $result
     */
    public static function contentsChanged(string $result): self
    {
        return new self(sprintf('The result %s holds other entries or fields after withContents() than it was given. ReadsContent::withContents() holds exactly the entries it is given, in their order, so no field the pipeline stripped is left.', $result));
    }

    public static function auditWithoutFields(EntryId $entry): self
    {
        return new self(sprintf('The read audit of the entry %s names no field. An entry is audited only for the fields that require it.', $entry->toString()));
    }

    public static function auditWithoutReads(CommandName $query): self
    {
        return new self(sprintf('The read audit of the query %s names no entry. A read is audited only when it returned a field that requires it.', $query->value));
    }
}
