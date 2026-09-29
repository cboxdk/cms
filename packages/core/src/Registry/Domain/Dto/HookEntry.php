<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * A hook in the registry (GUARDRAILS 2.4, PRD 6.3 and 13.2): the hook class, the command it runs
 * for by name, version and class, its phase, priority and time budget, and its package.
 *
 * A hook of an addon's package also names the addon and the highest classification the addon's
 * manifest lets the kernel hand it (PRD 13.1, invariant 21): the kernel gives it a view of the
 * plan with the fields up to the lower of that and the actor's classification access. A hook of a
 * package without a manifest, the application's or a module's, has neither, and sees what the
 * actor may read.
 */
#[Experimental]
final readonly class HookEntry
{
    public string $class;

    public string $package;

    public string $commandClass;

    public function __construct(
        string $class,
        string $package,
        public CommandName $command,
        public int $commandVersion,
        string $commandClass,
        public Phase $phase,
        public int $priority,
        public int $budgetMs,
        public ?AddonNamespace $addon = null,
        public ?ClassificationAccess $reads = null,
    ) {
        $this->class = InvalidRegistryEntry::checkClass('hook class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->commandClass = InvalidRegistryEntry::checkClass('hook command class', $commandClass);

        if ($commandVersion < 1) {
            throw InvalidRegistryEntry::because(sprintf('Hook "%s" runs for version %d of "%s". Versions start at 1.', $class, $commandVersion, $command->value));
        }

        if ($budgetMs < 1 || $budgetMs > Hook::MAX_BUDGET_MS) {
            throw InvalidRegistryEntry::because(sprintf('Hook "%s" has a budget of %d ms. It must be between 1 and %d ms.', $class, $budgetMs, Hook::MAX_BUDGET_MS));
        }

        if ((! $addon instanceof AddonNamespace) !== (! $reads instanceof ClassificationAccess)) {
            throw InvalidRegistryEntry::because(sprintf('Hook "%s" names %s. A hook of an addon names both the addon and what it reads, and any other hook neither.', $class, $addon instanceof AddonNamespace ? 'an addon but not what it reads' : 'what it reads but no addon'));
        }
    }
}
