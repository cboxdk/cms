<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What kind of work an operation does, such as "entries.rebuild": lower-case segments of letters,
 * digits and underscores separated by dots. The operations table stores it as the operation's kind.
 */
#[Experimental]
final readonly class OperationKind
{
    public const int MAX_LENGTH = 100;

    public const string PATTERN = '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/';

    /**
     * @throws InvalidOperation when the kind does not have the form of PATTERN or is longer than MAX_LENGTH
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidOperation::kind($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
