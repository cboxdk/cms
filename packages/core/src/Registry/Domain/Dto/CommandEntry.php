<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * A command DTO declared with #[Command]: its name and version, and the class that holds that
 * version (GUARDRAILS 2.1). A name and version belong to exactly one class.
 */
#[Experimental]
final readonly class CommandEntry
{
    public string $class;

    public string $package;

    public function __construct(
        public CommandName $name,
        public int $version,
        string $class,
        string $package,
    ) {
        if ($version < 1) {
            throw InvalidRegistryEntry::because(sprintf('Command "%s" has version %d. Versions start at 1.', $name->value, $version));
        }

        $this->class = InvalidRegistryEntry::checkClass('command class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
    }
}
