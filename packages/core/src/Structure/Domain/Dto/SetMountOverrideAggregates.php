<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use DateTimeImmutable;
use Override;

/**
 * What mount.set_override read (PRD 6.2 phase 1), at the time it read: the mount node, null when
 * no node has the id; the override the mount has for the entry, null when it has none; and what
 * the mount's source shows of the entry, null when no entry has the id. The mount node is the one
 * aggregate: an override changes its version, so two commands that read the mount at one version
 * commit one after the other and the second is version_conflict.
 */
#[Internal]
final readonly class SetMountOverrideAggregates implements Aggregates
{
    public function __construct(
        public NodeId $mount,
        public ?StoredNode $current,
        public EntryId $entry,
        public ?StoredMountOverride $override,
        public ?MountedEntry $mounted,
        public DateTimeImmutable $at,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(new ReadVersion($this->mount, $this->current?->version));
    }

    /**
     * The mount's node, in every locale: an exception to a mount is decided there (PRD 5.10).
     * Anywhere when the mount read as absent or outside the actor's regions.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->current instanceof StoredNode && $this->current->reachable
            ? AuthorizationScope::on(new AuthorizationTarget($this->mount))
            : AuthorizationScope::anywhere();
    }
}
