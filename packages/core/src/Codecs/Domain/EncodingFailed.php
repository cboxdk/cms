<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;
use Throwable;

/**
 * A generated codec was given a DTO it cannot encode in its contract's JSON form (GUARDRAILS 2.2):
 * a decimal that is not a decimal number of its field's precision and scale, text that is not
 * UTF-8, or a rich text value with a value JSON has no form for. The DTO was built wrong; the
 * codec writes nothing rather than a form the contract does not have.
 */
#[Experimental]
final class EncodingFailed extends LogicException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self('A generated codec cannot encode the DTO: '.$reason.'.', 0, $previous);
    }
}
