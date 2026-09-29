<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Override;

/**
 * The hooks a test registers for a name and version of a command, in the order it registers them.
 */
final class FakeCommandHooks implements CommandHooks
{
    /** @var array<string, list<BoundHook>> by "<name>@<version>" */
    private array $hooks = [];

    public function add(CommandName $command, int $version, BoundHook ...$hooks): self
    {
        $key = $command->value.'@'.$version;
        $this->hooks[$key] = [...$this->hooks[$key] ?? [], ...array_values($hooks)];

        return $this;
    }

    #[Override]
    public function for(CommandName $command, int $version): array
    {
        return $this->hooks[$command->value.'@'.$version] ?? [];
    }
}
