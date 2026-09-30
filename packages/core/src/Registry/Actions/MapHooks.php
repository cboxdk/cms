<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookMap;
use Cbox\Cms\Core\Registry\Domain\Dto\HookMapRequest;
use Cbox\Cms\Core\Registry\Domain\Dto\VersionHooks;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownCommand;

/**
 * The hook map of a command (PRD 13.2), as cms:hooks prints it: for each version of the command
 * the registry holds, lowest first, the hooks that run for it. They come from the same method of
 * the compiled registry the command pipeline takes its hooks from, CompiledRegistry::hooksOf(), so
 * the map is in the order they run: phase, then priority with the lowest first, then package, then
 * class (PRD 6.3).
 */
#[Experimental]
final readonly class MapHooks
{
    public function __construct(private RegistryCache $cache) {}

    /**
     * @throws UnknownCommand when no registered command has the name
     * @throws RegistryCacheMissing
     * @throws MalformedRegistryCache
     */
    public function map(HookMapRequest $request): HookMap
    {
        $registry = $this->cache->read();
        $versions = [];
        $registered = [];

        foreach ($registry->commands as $command) {
            $registered[$command->name->value] = $command->name;

            if ($command->name->equals($request->command)) {
                $versions[] = $command;
            }
        }

        if ($versions === []) {
            $query = array_any(
                $registry->actions,
                static fn (ActionEntry $action): bool => $action->kind === ActionKind::Query && $action->command->equals($request->command),
            );

            throw $query ? UnknownCommand::query($request->command) : UnknownCommand::notRegistered($request->command, array_values($registered));
        }

        usort($versions, static fn (CommandEntry $a, CommandEntry $b): int => $a->version <=> $b->version);

        return new HookMap($request->command, array_map(
            static fn (CommandEntry $command): VersionHooks => new VersionHooks($command->version, $registry->hooksOf($command->name, $command->version)),
            $versions,
        ));
    }
}
