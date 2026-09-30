<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;

/**
 * A release of the plan and the stored revision it names, read once through RevisionContents
 * before the hooks run (PRD 6.2 phases 2 to 5): the hooks see the revision's fields, and phase 5
 * validates the release against the same read. The content is null when the variant has no such
 * revision that the actor can reach, or when the type the release names is not in the catalog.
 */
#[Internal]
final readonly class ReleaseRead
{
    public function __construct(
        public VariantReleased $release,
        public ?RevisionContent $content,
    ) {}
}
