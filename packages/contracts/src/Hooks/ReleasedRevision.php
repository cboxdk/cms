<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;

/**
 * A revision a release of the plan makes public, with the fields it holds (PRD 6.3, 11.12,
 * invariant 36), as a hook sees it in the PlanView. A release names a revision that is stored
 * already, so the kernel reads its fields once, before the hooks run, and a validate hook can
 * require a field at the release that the entry's commands never require: an extension field of
 * an addon, or an owner's field tightened on release.
 *
 * Like a revision the plan creates, its fields are filtered to what the hook may read, and a
 * transform hook cannot change them: a release changes no field.
 */
#[Experimental]
final readonly class ReleasedRevision
{
    public function __construct(
        public VariantReleased $release,
        public FieldValues $fields,
    ) {}

    public function variant(): VariantRef
    {
        return new VariantRef($this->release->entry, $this->release->variant);
    }
}
