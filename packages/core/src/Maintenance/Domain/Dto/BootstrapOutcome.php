<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;

/**
 * What a run of the one-time access bootstrap did (PRD 5.10): refused before any command ran, with
 * the refusal; or the bootstrap role, whether the run's changeset creates it ($createsRole; false
 * when the role with the handle existed), the grant, and the result of access.bootstrap, the one
 * changeset that creates the role and grants it. The run is done when that changeset committed.
 */
#[Internal]
final readonly class BootstrapOutcome
{
    private function __construct(
        public ?CatalogError $refusal,
        public RoleHandle $handle,
        public ?ActorId $actor = null,
        public ?NodeId $node = null,
        public ?RoleId $role = null,
        public bool $createsRole = false,
        public ?GrantId $grant = null,
        public ?WriteResult $result = null,
    ) {}

    public static function refused(RoleHandle $handle, CatalogError $refusal): self
    {
        return new self($refusal, $handle);
    }

    /**
     * access.bootstrap ran with the result given: committed, with the role created when
     * $createsRole and the grant, or rejected, with neither.
     */
    public static function ran(BootstrapRequest $request, RoleHandle $handle, RoleId $role, bool $createsRole, GrantId $grant, WriteResult $result): self
    {
        return new self(null, $handle, $request->actor, $request->node, $role, $createsRole, $grant, $result);
    }

    /**
     * Whether the actor holds the bootstrap role on the node now.
     */
    public function done(): bool
    {
        return $this->result instanceof WriteResult && $this->result->outcome()->isCommitted();
    }

    /**
     * The errors that stopped the run: the refusal, or the errors of the rejected changeset; none
     * when it is done.
     *
     * @return list<CatalogError>
     */
    public function errors(): array
    {
        if ($this->refusal instanceof CatalogError) {
            return [$this->refusal];
        }

        return $this->result instanceof WriteResult && ! $this->result->outcome()->isCommitted() ? $this->result->errors : [];
    }
}
