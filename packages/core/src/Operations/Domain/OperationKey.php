<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Names one run of an operation kind, chosen by the caller. A run with the kind and key of an
 * operation that is still running resumes it, and one with the kind and key of a completed
 * operation does nothing; a new key starts a new operation. 1 to MAX_LENGTH visible ASCII
 * characters, compared exactly.
 */
#[Experimental]
final readonly class OperationKey
{
    public const int MAX_LENGTH = 200;

    /**
     * @throws InvalidOperation when the key is empty, longer than MAX_LENGTH or not visible ASCII
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw InvalidOperation::key($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
