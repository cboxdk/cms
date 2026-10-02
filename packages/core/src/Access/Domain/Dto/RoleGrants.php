<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * The grants of a role that have not ended, whoever holds them and wherever (PRD 5.10), sorted by
 * id, with the version of the role's set of grants (RoleGrantsRef): one more than the number of
 * grants ever given of the role.
 */
#[Internal]
final readonly class RoleGrants
{
    /**
     * @param  list<StoredGrant>  $grants
     */
    public function __construct(
        public array $grants,
        public AggregateVersion $version,
    ) {}
}
