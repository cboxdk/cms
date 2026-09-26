<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The addon field types that registered contributors provide (PRD 13.1, 13.3). A blueprint field
 * of type `<namespace>:<handle>` is read only when a contributor provides that type; the blueprint
 * schema checks just its form. The contributions come from the registry of schema contributions.
 */
#[Internal]
interface ContributedFieldTypes
{
    /**
     * Whether a registered contributor provides the field type.
     */
    public function provides(AddonFieldType $type): bool;
}
