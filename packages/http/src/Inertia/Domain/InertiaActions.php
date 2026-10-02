<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/**
 * The actions of the compiled registry that the Inertia profile exposes (GUARDRAILS 2.1): those
 * whose #[Action] lists Surface::Inertia. The panel and REST stay in parity, so building it from a
 * registry with an action on Inertia that is not on REST throws SurfaceParityBroken, naming every
 * such action, and the profile then serves nothing. find() gives the write action of a version of
 * a command and query() the query action of a version of a query, or null when the profile does
 * not expose one.
 */
#[Internal]
final readonly class InertiaActions
{
    /** @var array<string, ActionEntry> by name and version */
    private array $writes;

    /** @var array<string, ActionEntry> by name and version */
    private array $queries;

    /**
     * @throws SurfaceParityBroken when an action is exposed on Inertia and not on REST
     */
    public function __construct(CompiledRegistry $registry)
    {
        $writes = [];
        $queries = [];
        $broken = [];

        foreach ($registry->actions as $entry) {
            if (! $entry->exposes(Surface::Inertia)) {
                continue;
            }

            if (! $entry->exposes(Surface::Rest)) {
                $broken[] = $entry;
            }

            if ($entry->kind === ActionKind::Write) {
                $writes[$this->key($entry->command, $entry->commandVersion)] = $entry;
            } else {
                $queries[$this->key($entry->command, $entry->commandVersion)] = $entry;
            }
        }

        if ($broken !== []) {
            throw SurfaceParityBroken::inertiaWithoutRest(...$broken);
        }

        $this->writes = $writes;
        $this->queries = $queries;
    }

    public function find(CommandName $command, int $version): ?ActionEntry
    {
        return $this->writes[$this->key($command, $version)] ?? null;
    }

    /**
     * The query action of a version of a query, or null when the profile does not expose one.
     */
    public function query(CommandName $query, int $version): ?ActionEntry
    {
        return $this->queries[$this->key($query, $version)] ?? null;
    }

    private function key(CommandName $command, int $version): string
    {
        return $command->value.'@'.$version;
    }
}
