<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * One variant entry.publish version 2 publishes (PRD 6.4, 10.3): the variant, the shared one or the
 * language's of the locale the placement goes live in, the version of it the caller read, and the
 * number of its revision to release, or null for a type with stages none, whose content is public
 * as soon as it is saved.
 */
#[Experimental]
final readonly class VariantRelease
{
    public function __construct(
        public VariantKey $variant,
        public AggregateVersion $version,
        public ?RevisionNumber $revision,
    ) {}
}
