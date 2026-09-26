<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * The handle of a type, a field or a field type in the M0 fixture schema: lowercase snake_case
 * that starts with a letter, at most 63 characters, which is Postgres' limit for an identifier.
 * Milestone 1's blueprint schema v1 decides the final rule.
 */
#[Internal]
final readonly class Handle
{
    public const string PATTERN = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    public const int MAX_LENGTH = 63;

    /**
     * @throws GenerationFailed with GenerateErrorCode::SchemaInvalid
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || strlen($value) > self::MAX_LENGTH) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
                '"%s" is not a handle. Use lowercase snake_case that starts with a letter and has at most %d characters, such as "blog_post".',
                $value,
                self::MAX_LENGTH,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
