<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use LogicException;

/**
 * The kernel below the surfaces in a surface contract test (GUARDRAILS 9): the ExposedWorld's
 * fakes, with a ContractAction bound to every write action of the registry, and a committer each
 * scenario sets up. A surface runs the real RunExposedCommand and command pipeline over them: the
 * credential is verified, the command read by its generated codec, the envelope built from the
 * surface's input, the plan validated, a dry run stopped before the commit, and a commit's receipt
 * waited for. Nothing touches a database.
 */
final class ContractKernel
{
    public readonly ExposedWorld $exposed;

    public readonly ContractCommitter $committer;

    /** The fields of the revision the ContractAction plans in the current scenario. */
    private FieldValues $fields;

    private readonly FakeWriteActions $actions;

    public function __construct(CompiledRegistry $registry)
    {
        $this->exposed = new ExposedWorld;
        $this->committer = new ContractCommitter($this->exposed->world->committer);
        $this->fields = PipelineWorld::fields('Contract');

        $bindings = [];

        foreach ($registry->actions as $action) {
            if ($action->kind === ActionKind::Write) {
                $bindings[$this->commandClass($action->commandClass)] = new ActionBinding($action->command, $action->commandVersion, new ContractAction($this));
            }
        }

        $this->actions = new FakeWriteActions($bindings);
    }

    /**
     * The action every surface runs a write with.
     */
    public function action(): RunExposedCommand
    {
        return $this->exposed->action($this->actions, $this->committer);
    }

    /**
     * Sets the kernel up for the scenario: the fields the plan writes and how the commit ends.
     */
    public function script(Scenario $scenario): void
    {
        $this->fields = $scenario === Scenario::FieldError ? new FieldValues : PipelineWorld::fields('Contract');
        $this->committer->current = match ($scenario) {
            Scenario::VersionConflict => new FakeChangesetCommitter(new VersionConflict(new StaleRead($this->exposed->world->entry(), null, new AggregateVersion(1)))),
            Scenario::WaitTimeout => new FakeChangesetCommitter(new Committed(Receipt::committedWaitTimeout(
                ChangesetId::fromString(FakeChangesetCommitter::CHANGESET),
                WaitLevel::Origin,
                RetentionClass::Standard,
                new CommitPosition(FakeChangesetCommitter::POSITION),
            ))),
            default => $this->exposed->world->committing(),
        };
    }

    /**
     * The probe.rename the ContractAction plans for, with the scenario's fields.
     */
    public function probe(): RenameProbe
    {
        return $this->exposed->world->command($this->fields);
    }

    /**
     * The changesets handed to the committer in the current scenario.
     *
     * @return list<PendingChangeset>
     */
    public function pending(): array
    {
        return $this->committer->current->pending;
    }

    /**
     * @return class-string<Command>
     */
    private function commandClass(string $class): string
    {
        return is_a($class, Command::class, true) ? $class : throw new LogicException(sprintf('The registry names %s as a command, and it is no Command.', $class));
    }
}
