<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of a type in code, `<owner>:<handle>`, such as "app:blog_post" (PRD 11.12). A handle is
 * unique only for its owner, so two owners may each have a type with the same handle, and the name
 * holds both. The owner is "app" or the name of a module or addon: a lowercase letter followed by
 * at most 19 lowercase letters and digits, never "ext". The handle is lowercase snake_case of at
 * most 63 characters without a double underscore, never "ext" and never starting with "cms_".
 * The stable identity of a type is its TypeId; the name changes when the owner renames it.
 */
#[Experimental]
final readonly class TypeName
{
    private const string PATTERN = '/\A(?<owner>[a-z][a-z0-9]{0,19}):(?<handle>[a-z][a-z0-9]*(?:_[a-z0-9]+)*)\z/';

    public string $owner;

    public string $handle;

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1
            || $parts['owner'] === 'ext'
            || $parts['handle'] === 'ext'
            || str_starts_with($parts['handle'], 'cms_')
            || strlen($parts['handle']) > 63) {
            throw InvalidTypeDefinition::typeName($value);
        }

        $this->owner = $parts['owner'];
        $this->handle = $parts['handle'];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
