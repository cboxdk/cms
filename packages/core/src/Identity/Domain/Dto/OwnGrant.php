<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A grant the actor holds, as actor.me gives it (PRD 5.10): its id, the role with its handle, the
 * node it holds on, whether it allows or denies, the locales it holds in or null for every locale,
 * and its version. A grant that has ended is never among them.
 */
#[Experimental]
final readonly class OwnGrant
{
    /**
     * @param  list<Locale>|null  $locales
     */
    public function __construct(
        public GrantId $id,
        public RoleId $role,
        public RoleHandle $roleHandle,
        public NodeId $node,
        public GrantEffect $effect,
        public ?array $locales,
        public AggregateVersion $version,
    ) {}
}
