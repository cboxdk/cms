<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * A hook in the registry (GUARDRAILS 2.4, PRD 6.3 and 13.2): the hook class, the command it runs
 * for by name, version and class, its phase, priority and time budget, and its package.
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
        public string $command,
        public int $commandVersion,
        string $commandClass,
        public Phase $phase,
        public int $priority,
        public int $budgetMs,
    ) {
        $this->class = InvalidRegistryEntry::checkClass('hook class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->commandClass = InvalidRegistryEntry::checkClass('hook command class', $commandClass);

        if (preg_match(Command::NAME_PATTERN, $command) !== 1) {
            throw InvalidRegistryEntry::because(sprintf('Hook "%s" runs for "%s", which is not a command name.', $class, $command));
        }

        if ($commandVersion < 1) {
            throw InvalidRegistryEntry::because(sprintf('Hook "%s" runs for version %d of "%s". Versions start at 1.', $class, $commandVersion, $command));
        }

        if ($budgetMs < 1 || $budgetMs > Hook::MAX_BUDGET_MS) {
            throw InvalidRegistryEntry::because(sprintf('Hook "%s" has a budget of %d ms. It must be between 1 and %d ms.', $class, $budgetMs, Hook::MAX_BUDGET_MS));
        }
    }
}
