<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use RuntimeException;
use Throwable;

/**
 * A doctor probe could not find out what it was asked. The check that asked turns it into a
 * failing result with the kind and the cause.
 */
#[Internal]
final class ProbeFailed extends RuntimeException
{
    private function __construct(
        public readonly FailureKind $kind,
        public readonly string $cause,
        ?Throwable $previous,
    ) {
        parent::__construct($cause, 0, $previous);
    }

    /** The dependency could not be reached right now. */
    public static function unavailable(string $cause, ?Throwable $previous = null): self
    {
        return new self(FailureKind::Unavailable, $cause, $previous);
    }

    /** The dependency answered, and what it answered breaks the configuration or the contract. */
    public static function violation(string $cause, ?Throwable $previous = null): self
    {
        return new self(FailureKind::Violation, $cause, $previous);
    }
}
