<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/**
 * The writes of the compiled registry that the CLI surface runs (GUARDRAILS 2.1): the write
 * actions whose #[Action] lists Surface::Cli. They are what cms:run accepts, by the command's name
 * and version, so the CLI's signatures come from the registry cms:build compiles, and an action
 * that is not exposed on the CLI cannot be run from it. find() gives the action of a version of a
 * command, or null when the CLI does not expose one; signatures() lists them as
 * `<name> <version>`, sorted, for the command's help and its answer to an unknown command.
 */
#[Internal]
final readonly class CliActions
{
    /** @var array<string, ActionEntry> by name and version */
    private array $writes;

    public function __construct(CompiledRegistry $registry)
    {
        $writes = [];

        foreach ($registry->actions as $entry) {
            if ($entry->kind === ActionKind::Write && $entry->exposes(Surface::Cli)) {
                $writes[$this->key($entry->command, $entry->commandVersion)] = $entry;
            }
        }

        uasort($writes, static fn (ActionEntry $one, ActionEntry $other): int => [$one->command->value, $one->commandVersion] <=> [$other->command->value, $other->commandVersion]);

        $this->writes = $writes;
    }

    public function find(CommandName $command, int $version): ?ActionEntry
    {
        return $this->writes[$this->key($command, $version)] ?? null;
    }

    /**
     * @return list<string> each as `<name> <version>`, such as `entry.create 1`
     */
    public function signatures(): array
    {
        return array_map(
            static fn (ActionEntry $entry): string => $entry->command->value.' '.$entry->commandVersion,
            array_values($this->writes),
        );
    }

    private function key(CommandName $command, int $version): string
    {
        return $command->value.' '.$version;
    }
}
