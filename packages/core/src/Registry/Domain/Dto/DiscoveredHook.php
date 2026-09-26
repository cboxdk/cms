<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * A hook class declared with #[Hook], as the scanner found it. The command is still a class name;
 * the compiler resolves it to a registered command's name and version.
 */
#[Experimental]
final readonly class DiscoveredHook
{
    public string $class;

    public string $package;

    public string $commandClass;

    public function __construct(
        string $class,
        string $package,
        string $commandClass,
        public Phase $phase,
        public int $priority,
        public int $budgetMs,
    ) {
        $this->class = InvalidRegistryEntry::checkClass('hook class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->commandClass = InvalidRegistryEntry::checkClass('hook command class', $commandClass);

        if ($budgetMs < 1 || $budgetMs > Hook::MAX_BUDGET_MS) {
            throw InvalidRegistryEntry::because(sprintf('Hook "%s" has a budget of %d ms. It must be between 1 and %d ms.', $class, $budgetMs, Hook::MAX_BUDGET_MS));
        }
    }
}
