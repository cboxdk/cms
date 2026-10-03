<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * A command an addon's panel UI may issue (AddonCapabilities::$issues), resolved by cms:build to
 * the registered command and version its class declares.
 */
#[Experimental]
final readonly class IssuedCommand
{
    public string $class;

    public function __construct(
        public CommandRef $command,
        string $class,
    ) {
        $this->class = InvalidRegistryEntry::checkClass('issued command class', $class);
    }
}
