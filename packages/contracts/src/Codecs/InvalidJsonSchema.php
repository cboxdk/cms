<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Codecs;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The text given as a JsonSchema is not a JSON Schema document: not well-formed JSON, or not an
 * object.
 */
#[Experimental]
final class InvalidJsonSchema extends InvalidArgumentException
{
    public static function malformed(string $reason): self
    {
        return new self(sprintf('A JSON Schema must be well-formed JSON, and this text is not: %s.', $reason));
    }

    public static function notAnObject(): self
    {
        return new self('A JSON Schema of a contract version must be a JSON object, such as {"type": "object"}.');
    }
}
