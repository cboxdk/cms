<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The last segment of a placement's URL in one language (PRD 5.7, 5.9): the rest of a path after
 * the longest route prefix of its node is looked up as a slug. It is 1 to MAX_LENGTH characters
 * with no slash and no white space, and not "." or "..", so it is always exactly one segment of a
 * path. It is compared exactly, byte for byte; (node, locale, slug) is unique among the placements
 * that are not withdrawn (invariant 15).
 */
#[Experimental]
final readonly class Slug
{
    /** The longest slug, in characters. */
    public const int MAX_LENGTH = 255;

    public function __construct(public string $value)
    {
        if (
            $value === '.'
            || $value === '..'
            || preg_match('/\A[^\/\s]+\z/u', $value) !== 1
            || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH
        ) {
            throw InvalidContentValue::slug($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
