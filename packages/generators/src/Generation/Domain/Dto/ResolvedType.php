<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * A type with every top-level field it has once the extensions of all schema roots are applied:
 * its owner's fields and the extension fields of each extender, sorted by column name, and the
 * version of each extender's namespace, its part of the type's composite version (PRD 11.2).
 * DescriptorCompiler compiles it into the TypeDescriptor the generators read, and composes the
 * form's layout from the type's and the layout fragments of the extension files that add to it
 * (gap G2).
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
     * @param  list<ExtensionVersion>  $extensions  the extenders' parts of the composite version, sorted by namespace
     * @param  list<ExtensionBlueprint>  $extensionFiles  the extension files of other owners that add to the type, sorted by namespace and then by file
     */
    public function __construct(
        public TypeBlueprint $blueprint,
        public array $fields,
        public array $extensions,
        public array $extensionFiles = [],
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
}
