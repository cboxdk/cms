<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The host a request names (PRD 5.9 step 1): a DNS name, in lower case, and an optional port. Only
 * a configured host resolves to a site (PRD 8.10 point 7); the host of a request is never used to
 * build a canonical URL, which comes from the site's configured origin.
 */
#[Experimental]
final readonly class Host
{
    /** The longest DNS name, in characters, without the port. */
    public const int MAX_NAME_LENGTH = 253;

    private const string PATTERN = '/\A(?<name>[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)(?::(?<port>[1-9][0-9]{0,4}))?\z/';

    public string $value;

    /**
     * @throws InvalidRoutingValue for anything but a DNS name with an optional port
     */
    public function __construct(string $value)
    {
        $lower = strtolower($value);

        if (
            preg_match(self::PATTERN, $lower, $parts) !== 1
            || strlen($parts['name']) > self::MAX_NAME_LENGTH
            || (isset($parts['port']) && (int) $parts['port'] > 65535)
        ) {
            throw InvalidRoutingValue::host($value);
        }

        $this->value = $lower;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
