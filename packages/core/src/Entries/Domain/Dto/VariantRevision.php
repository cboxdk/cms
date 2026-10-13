<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * One variant's revision in entry.revise version 2 (PRD 10.1, 10.3): the variant, the shared one
 * or a language's, the version of it the caller read, and every field of the new revision, as a
 * revision is a whole snapshot of its variant.
 */
#[Experimental]
final readonly class VariantRevision
{
    public function __construct(
        public VariantKey $variant,
        public AggregateVersion $version,
        public FieldValues $fields,
    ) {}
}
