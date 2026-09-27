<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Something that registers field types in the FieldTypeRegistry (GUARDRAILS 2.4): the core with
 * CoreFieldTypes, and every other contributor through the same interface.
 */
#[Internal]
interface FieldTypeContributor
{
    /**
     * The module or addon whose name is the namespace of the contributed field types, each named
     * `<namespace>:<handle>` (PRD 13.1), or null for the core: only CoreFieldTypes registers field
     * types without a namespace. `app` and `ext` are reserved and never a contributor's namespace
     * (PRD 11.12).
     */
    public function owner(): ?Owner;

    /**
     * @return list<FieldType>
     */
    public function fieldTypes(): array;
}
