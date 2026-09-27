<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * A type with every top-level field it has once the extensions of all schema roots are applied:
 * its owner's fields and the extension fields of each extender, sorted by column name. The
 * generators take them apart with ownFields() and extensionFields(), because the generated code
 * addresses an extension field as `ext.<namespace>.<handle>` (PRD 11.12).
 *
 * The generated code names a type by its owner and handle, `<owner>:<handle>` as the other
 * namespaced names of PRD 13.1 and 11.12 are written, because a handle is unique only for its
 * owner: two owners may each have a type `product`, and a module that adds a type later never
 * collides with a type the application already has.
 */
#[Internal]
final readonly class ResolvedType
{
    /** Between the owner and the handle in the name of a type. */
    public const string SEPARATOR = ':';

    /**
     * @param  list<ResolvedField>  $fields  sorted by column name, each column once
     */
    public function __construct(
        public TypeBlueprint $blueprint,
        public array $fields,
    ) {}

    public function handle(): string
    {
        return $this->blueprint->handle->value;
    }

    public function owner(): Owner
    {
        return $this->blueprint->owner;
    }

    /**
     * The name of the type in generated code: `<owner>:<handle>`, such as `acme:product`. An owner
     * has no colon, so the name gives back its owner and handle, and no two types share one.
     */
    public function name(): string
    {
        return self::nameOf($this->blueprint->owner, $this->blueprint->handle);
    }

    public static function nameOf(Owner $owner, Handle $handle): string
    {
        return $owner->value.self::SEPARATOR.$handle->value;
    }

    /**
     * The owner's own fields, sorted by handle.
     *
     * @return list<ResolvedField>
     */
    public function ownFields(): array
    {
        $fields = [];

        foreach ($this->fields as $field) {
            if (! $field->namespace instanceof Owner) {
                $fields[$field->handle()] = $field;
            }
        }

        ksort($fields, SORT_STRING);

        return array_values($fields);
    }

    /**
     * The extension fields by the namespace of their extender, sorted by namespace and then by
     * handle.
     *
     * @return array<string, list<ResolvedField>>
     */
    public function extensionFields(): array
    {
        $namespaces = [];

        foreach ($this->fields as $field) {
            if ($field->namespace instanceof Owner) {
                $namespaces[$field->namespace->value][$field->handle()] = $field;
            }
        }

        ksort($namespaces, SORT_STRING);
        $sorted = [];

        foreach ($namespaces as $namespace => $fields) {
            ksort($fields, SORT_STRING);
            $sorted[$namespace] = array_values($fields);
        }

        return $sorted;
    }
}
