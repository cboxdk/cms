<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * The handle of a role (PRD 5.10), which one role has at most, as a unique constraint keeps it.
 * role.create reads it as absent, so the commit takes its advisory lock, and two creates of roles
 * with one handle and different ids commit one after the other and the second ends in
 * version_conflict, not in a unique violation. A handle a role has is version 1.
 */
#[Internal]
final readonly class RoleHandleRef implements AggregateRef
{
    public const string KIND = 'role_handle';

    public function __construct(public RoleHandle $handle) {}

    /**
     * "role_handle:" and the handle.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return self::KIND.':'.$this->handle->value;
    }
}
