<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A grant as grant.revoke reads it (PRD 5.10): its actor, role, node, effect and locale set, null
 * for every locale, its version, and whether it has ended (a revocation or a deactivation ended
 * it).
 */
#[Internal]
final readonly class StoredGrant
{
    /**
     * @param  list<Locale>|null  $locales
     */
    public function __construct(
        public GrantId $id,
        public ActorId $actor,
        public RoleId $role,
        public NodeId $node,
        public GrantEffect $effect,
        public ?array $locales,
        public AggregateVersion $version,
        public bool $ended,
    ) {}
}
