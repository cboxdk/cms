<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * The type editor descriptors of an installation (PRD 11.12, 14.1, GUARDRAILS 2.4): what the
 * panel's forms render a type from. The kernel knows no type by name: cms:generate compiles the
 * blueprints into a class in the application's generated code that implements this contract, and
 * the generated service provider binds it. A descriptor holds, per type, what the TypeCatalog does
 * not need and a form does: the labels, the descriptions, the choices of a select, how a group
 * repeats, an inferred title field and each field's validation rule in the form of the generated
 * validation runtime.
 *
 * Like the catalog, a TypeEditors is fixed for the life of the process, never reads the blueprints
 * or the database, and each call is cheap. The queries type.list and type.describe answer with its
 * descriptors, filtered to the reader (TypeEditor::visibleTo()).
 */
#[Experimental]
interface TypeEditors
{
    /**
     * Every descriptor, sorted by type name, each id and each name once.
     *
     * @return list<TypeEditor>
     */
    public function all(): array;

    /**
     * The descriptor of the type with the id, or null when the installation has none.
     */
    public function find(TypeId $id): ?TypeEditor;

    /**
     * The descriptor of the type with the name, `<owner>:<handle>`, or null when the installation
     * has none.
     */
    public function named(TypeName $name): ?TypeEditor;
}
