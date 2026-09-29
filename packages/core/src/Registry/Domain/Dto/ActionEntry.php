<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * An action in the registry (GUARDRAILS 2.1, PRD 13.2): the action class and its package, whether
 * it writes or reads, the command or query it handles by name, version and class, and the surfaces
 * it is exposed on, in the order of Surface's cases. A command or query has at most one action.
 */
#[Experimental]
final readonly class ActionEntry
{
    public string $class;

    public string $package;

    public string $commandClass;

    /** @var list<Surface> */
    public array $surfaces;

    /**
     * @param  list<Surface>  $surfaces  each once, in the order of Surface's cases
     */
    public function __construct(
        string $class,
        string $package,
        public ActionKind $kind,
        public CommandName $command,
        public int $commandVersion,
        string $commandClass,
        array $surfaces,
    ) {
        $this->class = InvalidRegistryEntry::checkClass('action class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->commandClass = InvalidRegistryEntry::checkClass(sprintf('action %s class', $kind->input()), $commandClass);
        $this->surfaces = InvalidRegistryEntry::checkSurfaces($class, $surfaces);

        if ($commandVersion < 1) {
            throw InvalidRegistryEntry::because(sprintf('Action "%s" handles version %d of "%s". Versions start at 1.', $class, $commandVersion, $command->value));
        }
    }

    public function exposes(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }
}
