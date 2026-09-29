<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;

/**
 * A property of a generated DTO and the key of its JSON form (GUARDRAILS 2.2).
 *
 * A required property is present and never null, unless it is nullable: then its key is present
 * and its value may be null. A property that is not required and has a default, a PHP expression,
 * may be missing from the JSON and then holds the default; it is null only when it is nullable. Any
 * other property may be null, and may be missing from the JSON, which the DTO holds as Omitted. A property with a classification above public is
 * withheld from a caller whose classification access does not allow it (PRD 12.2): the DTO holds
 * Omitted for it, and the JSON leaves it out. Null classification is a property that is never
 * withheld, such as a field inside a group, which is withheld with its group.
 */
#[Internal]
final readonly class CodecProperty
{
    /**
     * @param  string  $key  the JSON key
     * @param  string  $name  the PHP property, a camelCase identifier
     * @param  string  $description  what the property holds, for its PHPDoc
     * @param  bool  $nullable  whether a required property's value, or a defaulted one's, may be null
     * @param  ?string  $default  the PHP expression a property that is not required holds when it is missing, or null to hold Omitted
     */
    public function __construct(
        public string $key,
        public string $name,
        public CodecValue $value,
        public bool $required,
        public ?ClassificationAccess $classification,
        public string $description,
        public bool $nullable = false,
        public ?string $default = null,
    ) {}

    /**
     * Whether a caller may be denied the property: it is classified above public.
     */
    public function withheld(): bool
    {
        return $this->classification instanceof ClassificationAccess && $this->classification !== ClassificationAccess::Public;
    }

    /**
     * Whether the DTO may hold Omitted for the property.
     */
    public function omittable(): bool
    {
        return (! $this->required && $this->default === null) || $this->withheld();
    }

    /**
     * Whether the property may hold null.
     */
    public function mayBeNull(): bool
    {
        return (! $this->required && $this->default === null) || $this->nullable;
    }
}
