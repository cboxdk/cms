<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\RegistryName;

/**
 * The registries cms:build compiles and the application reads at run time (PRD 13.2).
 *
 * Commands are sorted by name, then version. Hooks are sorted by command name and version, then
 * phase in pipeline order (authorize, transform, validate), then priority with the lowest first,
 * then package, then class.
 */
#[Experimental]
final readonly class CompiledRegistry
{
    /**
     * @param  list<CommandEntry>  $commands
     * @param  list<HookEntry>  $hooks
     */
    public function __construct(
        public array $commands,
        public array $hooks,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    public function count(RegistryName $registry): int
    {
        return match ($registry) {
            RegistryName::Commands => count($this->commands),
            RegistryName::Hooks => count($this->hooks),
        };
    }
}
