<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\InvalidRoutingValue;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;

/**
 * Reads the arguments of cms:explain into the read of path.resolve (PRD 5.9) it explains: the URL's
 * host and its path, percent-decoded, with the locale of --locale, read as the anonymous principal,
 * so the explanation says why the public sees the page as it does. The query and the fragment of
 * the URL take no part in a resolution and are left out. A URL that is not absolute http or https,
 * a host or a path that path.resolve does not take (a trailing slash, an empty or dot segment), and
 * a missing or invalid locale are usage errors, exit 64.
 */
#[Internal]
final readonly class ExplainInput
{
    /** The schemes of a page's URL, in any case. */
    private const string SCHEME = '/\Ahttps?\z/i';

    /**
     * @throws CliCallRefused
     */
    public static function read(mixed $url, mixed $locale): QueryCall
    {
        if (! is_string($url)) {
            throw CliCallRefused::usage('Give the URL to explain, such as https://example.dk/nyheder/harbour.');
        }

        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || preg_match(self::SCHEME, $parts['scheme'] ?? '') !== 1
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw CliCallRefused::usage(sprintf('"%s" is not an absolute http or https URL without credentials, such as https://example.dk/nyheder/harbour.', $url));
        }

        try {
            $host = new Host(isset($parts['port']) ? $parts['host'].':'.$parts['port'] : $parts['host']);
            $path = new RequestPath(rawurldecode($parts['path'] ?? '/'));
        } catch (InvalidRoutingValue $invalid) {
            throw CliCallRefused::usage($invalid->getMessage(), $invalid);
        }

        if (! is_string($locale) || $locale === '') {
            throw CliCallRefused::usage('Give the locale to resolve the URL in with --locale, such as --locale=da.');
        }

        try {
            $language = new Locale($locale);
        } catch (InvalidContentValue $invalid) {
            throw CliCallRefused::usage($invalid->getMessage(), $invalid);
        }

        return new QueryCall(new ResolvePath($host, $language, $path), null);
    }
}
