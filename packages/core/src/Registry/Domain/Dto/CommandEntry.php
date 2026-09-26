<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Experimental;
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
        public string $name,
        public int $version,
        string $class,
        string $package,
    ) {
        if (preg_match(Command::NAME_PATTERN, $name) !== 1) {
            throw InvalidRegistryEntry::because(sprintf('The command name "%s" is not dot-separated snake_case.', $name));
        }

        if ($version < 1) {
            throw InvalidRegistryEntry::because(sprintf('Command "%s" has version %d. Versions start at 1.', $name, $version));
        }

        $this->class = InvalidRegistryEntry::checkClass('command class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
    }
}
