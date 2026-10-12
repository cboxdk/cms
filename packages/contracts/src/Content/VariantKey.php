<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Which variant of an entry: a language, or the shared variant "shared" that holds the fields that
 * do not vary by language (PRD 5.4, 10). The value is SHARED or the locale's tag.
 */
#[Experimental]
final readonly class VariantKey
{
    public const string SHARED = 'shared';

    public string $value;

    public ?Locale $locale;

    /**
     * @param  string  $value  SHARED, or a locale tag; anything else throws InvalidContentValue
     */
    public function __construct(string $value)
    {
        $this->locale = $value === self::SHARED ? null : new Locale($value);
        $this->value = $this->locale instanceof Locale ? $this->locale->value : self::SHARED;
    }

    public static function shared(): self
    {
        return new self(self::SHARED);
    }

    public static function of(Locale $locale): self
    {
        return new self($locale->value);
    }

    /**
     * SHARED, or a locale tag. Anything else throws InvalidContentValue.
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function isShared(): bool
    {
        return ! $this->locale instanceof Locale;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
