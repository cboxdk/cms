<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * A query DTO declared with #[Query], as the scanner found it: its name and version, and the class
 * that holds that version (GUARDRAILS 2.1). The compiler resolves a query action to it; the
 * registry keeps the name and version on the action's entry.
 */
#[Experimental]
final readonly class QueryEntry
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
            throw InvalidRegistryEntry::because(sprintf('Query "%s" has version %d. Versions start at 1.', $name->value, $version));
        }

        $this->class = InvalidRegistryEntry::checkClass('query class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
    }
}
