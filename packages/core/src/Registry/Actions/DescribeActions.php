<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionDescription;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;

/**
 * Every action of the compiled registry as cms:actions lists it (GUARDRAILS 7.1, PRD 13.2), in the
 * registry's order, by the name and then the version of the command or query each handles: its
 * surfaces, the permission a grant needs to allow it, and, for a write action, the hooks that run
 * for its command in the order they run.
 */
#[Experimental]
final readonly class DescribeActions
{
    public function __construct(private RegistryCache $cache) {}

    /**
     * @return list<ActionDescription>
     *
     * @throws RegistryCacheMissing
     * @throws MalformedRegistryCache
     */
    public function describe(): array
    {
        $registry = $this->cache->read();

        return array_map(
            static fn (ActionEntry $action): ActionDescription => new ActionDescription(
                $action,
                $action->kind === ActionKind::Write ? $registry->hooksOf($action->command, $action->commandVersion) : [],
            ),
            $registry->actions,
        );
    }
}
