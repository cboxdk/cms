<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * The handle of a type, a field or a select option (PRD 11.2, 11.12), by the rule of `$defs/handle`
 * in the blueprint schema v1: lowercase snake_case that starts with a letter and has no double
 * underscore, at most 63 characters, which is Postgres' limit for an identifier. `ext` holds the
 * extension fields and the prefix `cms_` the system columns of a type table, so neither is a
 * handle.
 */
#[Internal]
final readonly class Handle
{
    public const string PATTERN = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    public const int MAX_LENGTH = 63;

    /** The handle that extension fields are nested under (PRD 11.12). */
    public const string RESERVED = 'ext';

    /** The prefix of the system columns of a type table. */
    public const string RESERVED_PREFIX = 'cms_';

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

        if ($value === self::RESERVED || str_starts_with($value, self::RESERVED_PREFIX)) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
                '"%s" is reserved. "%s" and handles that start with "%s" belong to the CMS; choose another handle.',
                $value,
                self::RESERVED,
                self::RESERVED_PREFIX,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
