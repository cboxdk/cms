<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A host, a request path, a site handle, a site's origin or a site configuration that breaks its
 * invariants (PRD 5.9).
 */
#[Experimental]
final class InvalidRoutingValue extends InvalidArgumentException
{
    public static function host(string $value): self
    {
        return new self(sprintf(
            'A host is a DNS name of letters, digits and hyphens in dot-separated labels, at most %d characters, with an optional port from 1 to 65535, such as "example.dk" or "localhost:8000", got "%s".',
            Host::MAX_NAME_LENGTH,
            self::shown($value),
        ));
    }

    public static function path(string $value): self
    {
        return new self(sprintf(
            'A request path is "/" or at most %d segments, each a "/" followed by characters that are neither a slash nor white space and not "." or "..", with no trailing slash and at most %d bytes, got "%s".',
            RequestPath::MAX_SEGMENTS,
            RequestPath::MAX_BYTES,
            self::shown($value),
        ));
    }

    public static function siteHandle(string $value): self
    {
        return new self(sprintf(
            'A site handle is a lower-case letter followed by at most 62 lower-case letters, digits or underscores, as the sites table holds it, got "%s".',
            self::shown($value),
        ));
    }

    public static function origin(string $value): self
    {
        return new self(sprintf(
            'A site origin is "https://" or "http://" followed by a host and an optional port, with no path, query or fragment, such as "https://example.dk", got "%s".',
            self::shown($value),
        ));
    }

    public static function duplicateHost(Host $host, SiteHandle $first, SiteHandle $second): self
    {
        return new self(sprintf(
            'The host "%s" is configured for both the sites "%s" and "%s"; a host belongs to one site.',
            $host->value,
            $first->value,
            $second->value,
        ));
    }

    public static function duplicateSite(SiteHandle $site): self
    {
        return new self(sprintf('The site "%s" is configured twice.', $site->value));
    }

    /**
     * The value as a message shows it: at most 100 characters of valid UTF-8.
     */
    private static function shown(string $value): string
    {
        $valid = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return mb_strlen($valid, 'UTF-8') > 100 ? mb_substr($valid, 0, 100, 'UTF-8').'...' : $valid;
    }
}
