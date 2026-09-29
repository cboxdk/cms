<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Errors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A problem details document that does not answer its code as the error catalog says.
 */
#[Experimental]
final class InvalidProblem extends InvalidArgumentException
{
    public static function type(ErrorCode $code, string $type): self
    {
        return new self(sprintf(
            'The type of a problem with the code %s is "%s", the section of the error reference for the code, got "%s".',
            $code->value,
            $code->entry()->docs(),
            self::shown($type),
        ));
    }

    public static function status(ErrorCode $code, int $status): self
    {
        return new self(sprintf(
            'The status of a problem with the code %s is %d, as the error catalog says, got %d.',
            $code->value,
            $code->entry()->http->value,
            $status,
        ));
    }

    public static function retryable(ErrorCode $code): self
    {
        return new self(sprintf(
            'A problem with the code %s is %s, as the error catalog says.',
            $code->value,
            $code->entry()->retryable ? 'retryable' : 'not retryable',
        ));
    }

    public static function emptyText(ErrorCode $code, string $member): self
    {
        return new self(sprintf('The %s of a problem with the code %s is empty; it needs text.', $member, $code->value));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > 64 ? substr($value, 0, 64).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
