<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * One header of an outbound request: a token as RFC 9110 defines a field name, and a value without
 * control characters, so no header can be split into two.
 */
#[Experimental]
final readonly class EgressHeader
{
    public const string NAME_PATTERN = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';

    public const string VALUE_PATTERN = '/\A[^\x00-\x08\x0A-\x1F\x7F]*\z/';

    /**
     * @throws InvalidArgumentException when the name is not a token or the value holds a control character
     */
    public function __construct(public string $name, public string $value)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('A header name is a token of RFC 9110.');
        }

        if (preg_match(self::VALUE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The value of the header %s holds a control character.', $name));
        }
    }
}
