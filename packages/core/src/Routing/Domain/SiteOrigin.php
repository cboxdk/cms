<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where a site is served (PRD 5.9, 8.10 point 7): the scheme, https or http, and the host, with an
 * optional port and no path. A canonical URL is built from the origin of its site, never from the
 * host a request names.
 */
#[Experimental]
final readonly class SiteOrigin
{
    private const string PATTERN = '/\A(?<scheme>https?):\/\/(?<host>[^\/?#]+)\z/';

    public string $value;

    public Host $host;

    /**
     * @throws InvalidRoutingValue for anything but a scheme and a host
     */
    public function __construct(string $value)
    {
        $lower = strtolower($value);

        if (preg_match(self::PATTERN, $lower, $parts) !== 1) {
            throw InvalidRoutingValue::origin($value);
        }

        try {
            $this->host = new Host($parts['host']);
        } catch (InvalidRoutingValue) {
            throw InvalidRoutingValue::origin($value);
        }

        $this->value = $parts['scheme'].'://'.$this->host->value;
    }

    /**
     * The absolute URL of a path below the origin, the path starting with a slash.
     */
    public function url(string $path): string
    {
        return $this->value.$path;
    }
}
