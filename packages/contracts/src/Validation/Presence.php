<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Whether a field must have a value that is not null (PRD 11.8, 11.12).
 *
 * An extension field that its blueprint marks required is RequiredOnRelease: the owner's code
 * creates and revises entries without knowing the extension, so the value is required only when an
 * entry is released (PRD 11.12 point 1, invariant 36).
 */
#[Experimental]
enum Presence: string
{
    /** Every write needs a value. */
    case Required = 'required';

    /** A release needs a value; a write that does not release may leave it out. */
    case RequiredOnRelease = 'required_on_release';

    /** The value may be left out or null. */
    case Optional = 'optional';

    /**
     * Whether a value is required at the stage.
     */
    public function requiredAt(ValidationStage $stage): bool
    {
        return $this === self::Required || ($this === self::RequiredOnRelease && $stage === ValidationStage::Release);
    }
}
