<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Codecs;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;
use Throwable;

/**
 * The text of a JsonDocument is not one well-formed JSON object. The message never repeats the
 * text, which may hold content.
 */
#[Experimental]
final class InvalidJsonDocument extends InvalidArgumentException
{
    public static function malformed(Throwable $previous): self
    {
        return new self('A JSON document is not well-formed JSON, or nests too deep: '.$previous->getMessage(), 0, $previous);
    }

    public static function notAnObject(): self
    {
        return new self('A JSON document is one JSON object, not another JSON value.');
    }
}
