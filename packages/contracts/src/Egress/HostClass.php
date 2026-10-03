<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The kind of destination an outbound request goes to, named by the code that sends it, such as
 * breached_passwords: a lowercase word of letters, digits and underscores, at most 40 characters.
 * The gateway counts its requests and failures under it (GUARDRAILS 5), so telemetry says what was
 * called without the host, the path or the query of the URL, which may carry secrets or personal
 * data (GUARDRAILS 6), and with a fixed number of values.
 */
#[Experimental]
final readonly class HostClass
{
    public const string PATTERN = '/\A[a-z][a-z0-9_]{0,39}\z/';

    /**
     * @throws InvalidArgumentException when the value is not in the form of PATTERN
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A host class is a lowercase word of letters, digits and underscores that starts with a letter, at most 40 characters.');
        }
    }
}
