<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;

/**
 * What a grant command gives that the escalation guard holds against the issuing actor (PRD 5.10,
 * invariant 31): the role with its classification ceiling and permissions, on the node, in the
 * locales, or in every locale for null.
 */
#[Internal]
final readonly class RoleGrant
{
    /**
     * @param  list<CommandName>  $permissions  the command and query names the role may run
     * @param  list<Locale>|null  $locales
     */
    public function __construct(
        public RoleId $role,
        public ClassificationAccess $ceiling,
        public array $permissions,
        public NodeId $node,
        public ?array $locales = null,
    ) {}
}
