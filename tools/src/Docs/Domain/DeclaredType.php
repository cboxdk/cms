<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * A named class, interface, trait or enum as a PHP file declares it: its fully qualified name, its
 * kind, the fully qualified names of the attributes on the declaration and the line of its keyword.
 */
final readonly class DeclaredType
{
    /**
     * @param  list<string>  $attributes
     */
    public function __construct(
        public string $name,
        public TypeKind $kind,
        public array $attributes,
        public int $line,
    ) {}

    public function has(string $attribute): bool
    {
        return in_array($attribute, $this->attributes, true);
    }
}
