<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The formats of a `text` field of the blueprint schema v1 besides plain text (PRD 11.12), and of
 * the `href` of a link in rich text: `email`, an address PHP's email filter accepts, and `url`, an
 * absolute http or https URL.
 */
#[Internal]
final readonly class TextFormats
{
    /** The start of a URL a browser opens as a page: the scheme http or https, in any case. */
    private const string WEB_URL = '/\Ahttps?:\/\//i';

    /**
     * Whether $value is in $format. Another format matches nothing.
     */
    public static function matches(string $format, string $value): bool
    {
        return match ($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match(self::WEB_URL, $value) === 1,
            default => false,
        };
    }
}
