<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedProfile;

/**
 * A grant that has not ended as grant.list gives it (PRD 5.10): its id, the actor it is granted to
 * with the actor's profile, or null when the reader may not read the profile, the role with its
 * handle, the node with its path label, the effect, the locales it holds in or null for every
 * locale, and its version.
 */
#[Experimental]
final readonly class ListedGrant
{
    /**
     * @param  list<Locale>|null  $locales
     */
    public function __construct(
        public GrantId $id,
        public ActorId $actor,
        public ?ListedProfile $profile,
        public RoleId $role,
        public RoleHandle $roleHandle,
        public NodeId $node,
        public string $nodeLabel,
        public GrantEffect $effect,
        public ?array $locales,
        public AggregateVersion $version,
    ) {}

    /**
     * This grant as a reader with $access may see it.
     */
    public function visibleTo(ClassificationAccess $access): self
    {
        return new self(
            $this->id,
            $this->actor,
            $this->profile?->visibleTo($access),
            $this->role,
            $this->roleHandle,
            $this->node,
            $this->nodeLabel,
            $this->effect,
            $this->locales,
            $this->version,
        );
    }
}
