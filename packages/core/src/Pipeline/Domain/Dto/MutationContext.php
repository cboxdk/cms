<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use DateTimeImmutable;

/**
 * What a MutationWriter writes a mutation with (PRD 6.2 phase 7): the changeset it belongs to, its
 * time, the actor, and the version the changeset leaves the mutation's aggregate at, one higher
 * than the version the command read, or 1 for an aggregate the command read as absent. Every
 * mutation of one aggregate in a changeset gets the same version, so an aggregate moves up by one
 * per changeset.
 */
#[Internal]
final readonly class MutationContext
{
    public function __construct(
        public ChangesetId $changesetId,
        public DateTimeImmutable $at,
        public ActorId $actor,
        public AggregateVersion $version,
    ) {}
}
