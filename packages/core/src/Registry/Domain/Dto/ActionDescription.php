<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * One action as cms:actions describes it (GUARDRAILS 7.1, PRD 13.2): its registry entry, with the
 * command or query it handles and the surfaces it is exposed on; the permission a grant needs to
 * allow it, the name a role's permissions (`role_permissions`) hold, which is the name of the
 * command or query; and the hooks that run for it, in the order they run. A query action has no
 * hooks, because hooks run in the command pipeline alone.
 */
#[Experimental]
final readonly class ActionDescription
{
    public CommandName $permission;

    /**
     * @param  list<HookEntry>  $hooks  in the order they run
     */
    public function __construct(
        public ActionEntry $action,
        public array $hooks,
    ) {
        $this->permission = $action->command;
    }
}
