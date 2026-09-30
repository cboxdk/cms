<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Codecs;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\TypeId;
use Throwable;
use UnexpectedValueException;

/**
 * RecordCodecs could not write the record of an entry: the installation has no codec for its type,
 * or the type's contract refused its field values. Either is a fault of the installation, never of
 * the caller: generated code that does not match the stored rows.
 */
#[Experimental]
final class InvalidRecordDocument extends UnexpectedValueException
{
    public static function unknownType(TypeId $type): self
    {
        return new self(sprintf('The installation has no record codec for the type %s. Run cms:generate and deploy the generated code.', $type->toString()));
    }

    public static function refused(TypeId $type, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('The record of the type %s cannot be written: %s', $type->toString(), $reason), 0, $previous);
    }
}
