<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A link that breaks its rules: a URL that is not an absolute http or https URL of at most
 * Link::MAX_URL_LENGTH characters, a rel given twice, or a field value that is not a link.
 */
#[Experimental]
final class InvalidLink extends InvalidArgumentException
{
    public static function url(): self
    {
        return new self(sprintf('A link\'s URL is an absolute http or https URL of at most %d characters.', Link::MAX_URL_LENGTH));
    }

    public static function rel(LinkRel $rel): self
    {
        return new self(sprintf('A link names the rel "%s" twice.', $rel->value));
    }

    public static function value(string $why): self
    {
        return new self(sprintf('The value is not a link: %s.', $why));
    }
}
