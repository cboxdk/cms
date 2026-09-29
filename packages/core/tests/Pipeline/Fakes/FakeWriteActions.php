<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Override;

/**
 * The write actions a test registers, by command class. WriteActionsBehaviour holds it to
 * RegistryWriteActions.
 */
final readonly class FakeWriteActions implements WriteActions
{
    /** @var array<string, ActionBinding> by lower-case command class */
    private array $bindings;

    /**
     * @param  array<class-string<Command>, ActionBinding>  $bindings
     */
    public function __construct(array $bindings)
    {
        $byClass = [];

        foreach ($bindings as $class => $binding) {
            $byClass[strtolower($class)] = $binding;
        }

        $this->bindings = $byClass;
    }

    #[Override]
    public function for(Command $command): ActionBinding
    {
        return $this->bindings[strtolower($command::class)] ?? throw UnknownCommand::noAction($command::class);
    }
}
