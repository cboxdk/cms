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
 * the refusal; or the bootstrap role, with the result of role.create when it created it (null when
 * the role with the handle existed), and the result of grant.assign when it ran. The run is done
 * when the grant committed.
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
        public ?WriteResult $roleCreated = null,
        public ?GrantId $grant = null,
        public ?WriteResult $granted = null,
    ) {}

    public static function refused(RoleHandle $handle, CatalogError $refusal): self
    {
        return new self($refusal, $handle);
    }

    /**
     * The role is in place, created now or found, and the grant ran with the result given, or did
     * not run because role.create was rejected.
     */
    public static function ran(BootstrapRequest $request, RoleHandle $handle, RoleId $role, ?WriteResult $roleCreated, ?GrantId $grant, ?WriteResult $granted): self
    {
        return new self(null, $handle, $request->actor, $request->node, $role, $roleCreated, $grant, $granted);
    }

    /**
     * Whether the actor holds the bootstrap role on the node now.
     */
    public function done(): bool
    {
        return $this->granted instanceof WriteResult && $this->granted->outcome()->isCommitted();
    }

    /**
     * The errors that stopped the run: the refusal, or the errors of the command that was rejected;
     * none when it is done.
     *
     * @return list<CatalogError>
     */
    public function errors(): array
    {
        if ($this->refusal instanceof CatalogError) {
            return [$this->refusal];
        }

        if ($this->roleCreated instanceof WriteResult && ! $this->roleCreated->outcome()->isCommitted()) {
            return $this->roleCreated->errors;
        }

        return $this->granted instanceof WriteResult && ! $this->granted->outcome()->isCommitted() ? $this->granted->errors : [];
    }
}
