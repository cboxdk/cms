<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Illuminate\Contracts\Container\Container;
use Override;

/**
 * The write actions of the compiled registry (PRD 13.2): the action cms:build registered for the
 * command's class, built by the container so it gets its read ports through its constructor, with
 * the name and version of the command from the registry.
 */
#[Internal]
final readonly class RegistryWriteActions implements WriteActions
{
    public function __construct(
        private CompiledRegistry $registry,
        private Container $container,
    ) {}

    #[Override]
    public function for(Command $command): ActionBinding
    {
        $entry = $this->registry->actionFor($command::class);

        if (! $entry instanceof ActionEntry || $entry->kind !== ActionKind::Write) {
            throw UnknownCommand::noAction($command::class);
        }

        $action = $this->container->make($entry->class);

        if (! $action instanceof WriteAction) {
            throw UnknownCommand::notAWriteAction($command::class, $entry->class);
        }

        return new ActionBinding($entry->command, $entry->commandVersion, $action);
    }
}
