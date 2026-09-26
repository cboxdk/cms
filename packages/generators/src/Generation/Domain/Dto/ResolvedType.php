<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * A type with every top-level field it has once the extensions of all schema roots are applied:
 * its owner's fields and the extension fields of each extender, sorted by column name. The
 * generators take them apart with ownFields() and extensionFields(), because the generated code
 * addresses an extension field as `ext.<namespace>.<handle>` (PRD 11.12).
 */
#[Internal]
final readonly class ResolvedType
{
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
