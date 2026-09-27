<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A check id, a check result or a list of checks breaks the rules of the doctor contract (PRD 3.3).
 */
#[Experimental]
final class InvalidDoctorCheck extends InvalidArgumentException
{
    public static function id(string $value): self
    {
        return new self(sprintf(
            'The check id "%s" is invalid. A check id is two or more lowercase snake_case segments separated by dots, such as "postgres.reachable", and at most %d characters.',
            $value,
            CheckId::MAX_LENGTH,
        ));
    }

    public static function code(CheckId $id, string $code): self
    {
        return new self(sprintf(
            'The error code "%s" of check "%s" is invalid. An error code is lowercase snake_case with at least two words, such as "doctor_postgres_unreachable", and at most %d characters.',
            $code,
            $id->value,
            CheckResult::MAX_CODE_LENGTH,
        ));
    }

    public static function emptyText(CheckId $id, string $field): self
    {
        return new self(sprintf('The %s of check "%s" is empty. Every result explains itself in plain words.', $field, $id->value));
    }

    public static function field(CheckId $id, CheckStatus $status, string $field, bool $required): self
    {
        return new self(sprintf(
            'A %s result of check "%s" %s a %s.',
            $status->value,
            $id->value,
            $required ? 'needs' : 'cannot have',
            $field,
        ));
    }

    public static function duplicate(CheckId $id): self
    {
        return new self(sprintf('The check "%s" is listed twice. Every check has its own id.', $id->value));
    }

    public static function requirement(CheckId $id, CheckId $required): self
    {
        return new self(sprintf(
            'The check "%s" requires "%s", which is not listed before it. A check can only require a check that runs earlier.',
            $id->value,
            $required->value,
        ));
    }

    public static function blockingRequirement(CheckId $id, CheckId $required): self
    {
        return new self(sprintf(
            'The blocking check "%s" requires "%s", which does not block. A blocking check can only require blocking checks, because a readiness failure that skips it would let the kernel start without it.',
            $id->value,
            $required->value,
        ));
    }
}
