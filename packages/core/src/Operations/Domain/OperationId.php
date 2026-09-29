<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The id laravel-operations gives an operation, such as "op_01k…". It is opaque: the kernel only
 * stores and compares it.
 */
#[Experimental]
final readonly class OperationId
{
    /**
     * @throws InvalidOperation when the id is empty, longer than 255 characters or not visible ASCII
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > 255 || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw InvalidOperation::id($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
