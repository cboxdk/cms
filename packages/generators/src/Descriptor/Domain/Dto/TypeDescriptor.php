<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\ExtensionVersion;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedType;
use Cbox\Cms\Generators\Schema\Domain\Dto\Capabilities;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TypeId;

/**
 * A type compiled for the generators (PRD 11.2, 11.12): its identity, its owner's version, the
 * version of each extender's namespace, its capabilities and its top-level fields sorted by column.
 * Every generator reads the descriptor, never the blueprint, and TypeDescriptorJson gives its
 * canonical JSON, the form a test compares with a committed golden file and that schema_versions
 * stores (PRD 11.2).
 */
#[Internal]
final readonly class TypeDescriptor
{
    /**
     * @param  int  $version  the owner's version of the definition
     * @param  list<ExtensionVersion>  $extensions  sorted by namespace, each once
     * @param  list<FieldDescriptor>  $fields  sorted by column name, each column once
     */
    public function __construct(
        public TypeId $typeId,
        public Owner $owner,
        public Handle $handle,
        public string $label,
        public ?string $description,
        public int $version,
        public Capabilities $capabilities,
        public array $extensions,
        public array $fields,
        public SourceLocation $location,
    ) {}

    /**
     * The name of the type in generated code, `<owner>:<handle>` (ResolvedType::name()).
     */
    public function name(): string
    {
        return ResolvedType::nameOf($this->owner, $this->handle);
    }

    /**
     * The owner's own fields, sorted by handle.
     *
     * @return list<FieldDescriptor>
     */
    public function ownFields(): array
    {
        return array_values(array_filter($this->fields, static fn (FieldDescriptor $field): bool => ! $field->namespace instanceof Owner));
    }

    /**
     * The extension fields by the namespace of their extender, sorted by namespace and then by
     * handle.
     *
     * @return array<string, list<FieldDescriptor>>
     */
    public function extensionFields(): array
    {
        $namespaces = [];

        foreach ($this->fields as $field) {
            if ($field->namespace instanceof Owner) {
                $namespaces[$field->namespace->value][] = $field;
            }
        }

        ksort($namespaces, SORT_STRING);

        return $namespaces;
    }
}
