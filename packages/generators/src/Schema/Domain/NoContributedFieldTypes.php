<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Override;

/**
 * The contributed field types of an installation without contributors: none. It is the binding
 * until the registry of schema contributions (PRD 13.3, milestone 1 point 7) registers addons'
 * field types, so every addon field type in a blueprint file is reported as unknown.
 */
#[Internal]
final readonly class NoContributedFieldTypes implements ContributedFieldTypes
{
    #[Override]
    public function provides(AddonFieldType $type): bool
    {
        return false;
    }
}
