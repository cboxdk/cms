<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One entry of a MapValue: a key of 1 to 255 bytes of UTF-8 and its value.
 */
#[Experimental]
final readonly class MapEntry
{
    public function __construct(
        public string $key,
        public FieldValue $value,
    ) {
        if ($key === '' || strlen($key) > 255 || ! mb_check_encoding($key, 'UTF-8')) {
            throw InvalidFieldValue::mapKey($key);
        }
    }
}
