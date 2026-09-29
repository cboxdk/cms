<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The namespace of the fields one extender adds to a type it does not own (PRD 11.12, 13.3): the
 * application's "app", or a module's or addon's name. It is a lowercase letter followed by at
 * most 19 lowercase letters and digits, and never "ext".
 */
#[Experimental]
final readonly class FieldNamespace
{
    private const string PATTERN = '/\A[a-z][a-z0-9]{0,19}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || $value === 'ext') {
            throw InvalidFieldValue::fieldNamespace($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
