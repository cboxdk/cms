<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The handle of a field, as its blueprint declares it (PRD 11.12, blueprint schema v1): lowercase
 * snake_case of at most 63 bytes, without a double underscore. "ext" and the prefix "cms_"
 * are reserved and never a handle.
 */
#[Experimental]
final readonly class FieldHandle
{
    private const string PATTERN = '/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/';

    public function __construct(public string $value)
    {
        if (strlen($value) > 63
            || preg_match(self::PATTERN, $value) !== 1
            || $value === 'ext'
            || str_starts_with($value, 'cms_')) {
            throw InvalidFieldValue::handle($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
