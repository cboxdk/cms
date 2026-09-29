<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A language, as a BCP 47 tag of a language subtag, an optional script and an optional region,
 * such as "da", "en-GB", "sr-Latn" or "es-419" (PRD 10). The tag is stored in its canonical case:
 * the language in lower case, the script in title case and the region in upper case, so "EN-gb"
 * and "en-GB" are the same locale.
 */
#[Experimental]
final readonly class Locale
{
    private const string PATTERN = '/\A([a-z]{2,3})(?:-([a-z]{4}))?(?:-([a-z]{2}|[0-9]{3}))?\z/i';

    public string $value;

    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1) {
            throw InvalidContentValue::locale($value);
        }

        $tag = strtolower($parts[1]);

        if (($parts[2] ?? '') !== '') {
            $tag .= '-'.ucfirst(strtolower($parts[2]));
        }

        if (($parts[3] ?? '') !== '') {
            $tag .= '-'.strtoupper($parts[3]);
        }

        $this->value = $tag;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
