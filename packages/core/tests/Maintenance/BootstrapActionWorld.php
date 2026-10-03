<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Access\Actions\AssignGrantAction;
use Cbox\Cms\Core\Access\Actions\CreateRoleAction;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Maintenance\Actions\BootstrapAccess;
use Cbox\Cms\Core\Maintenance\Actions\GrantBootstrapRoleAction;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\Commands\GrantBootstrapRole;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapSettings;
use Cbox\Cms\Core\Maintenance\Domain\MaintenanceAuthorizer;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeGrantReader;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissionCatalog;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\CommittedRoles;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeAccessBootstrapState;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakePublicPlacements;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;

/**
 * The one-time access bootstrap with the fakes of the ports it reads (GUARDRAILS 9): an installed
 * service operator, an active staff member, a node, and a registry of COMMANDS and QUERIES. Its
 * command runs through the real RunMaintenanceCommand and the kernel's command pipeline with the
 * bootstrap's MaintenanceAuthorizer, the real access.bootstrap action over the real role.create and
 * grant.assign actions, the fake committer, and one idempotency and receipt store for every run,
 * so a rerun can replay. The GrantReader the actions read also knows the roles the committer has
 * committed, as the database would.
 */
final class BootstrapActionWorld
{
    /** @var list<string> */
    public const array COMMANDS = ['entry.create', 'grant.assign', 'role.create'];

    /** @var list<string> */
    public const array QUERIES = ['path.resolve'];

    public readonly FakeIdentity $identity;

    public readonly ActorId $operator;

    public readonly ActorId $staff;

    public readonly NodeId $node;

    public readonly FakeAccessBootstrapState $state;

    public readonly FakeGrantReader $reader;

    public readonly FakeInstallationOperator $installation;

    public FakeChangesetCommitter $committer;

    public BootstrapSettings $settings;

    private readonly FakeClock $clock;

    private readonly FakeIdempotencyStore $keys;

    private readonly FakeReceiptStore $receipts;

    private readonly FakeIdGenerator $ids;

    public function __construct()
    {
        $this->clock = new FakeClock;
        $this->ids = new FakeIdGenerator(seed: 2424, clock: $this->clock);
        $this->identity = new FakeIdentity;
        $this->operator = $this->identity->addActor(ActorClass::Service)->id;
        $this->staff = $this->identity->addActor(ActorClass::Staff)->id;
        $this->node = new NodeId($this->ids->next());
        $this->installation = new FakeInstallationOperator($this->operator);
        $this->state = new FakeAccessBootstrapState($this->operator);
        $this->state->addNode($this->node);
        $this->reader = new FakeGrantReader([$this->node]);
        $this->committer = new FakeChangesetCommitter;
        $this->settings = new BootstrapSettings(new RoleHandle('administrator'), false);
        $this->keys = new FakeIdempotencyStore($this->clock);
        $this->receipts = new FakeReceiptStore($this->clock);
    }

    public function action(): BootstrapAccess
    {
        return new BootstrapAccess(
            $this->settings,
            $this->installation,
            new FakeAccessContexts,
            $this->state,
            $this->identity,
            $this->ids,
            $this->registry(),
            new RunMaintenanceCommand($this->installation, new FakeAccessContexts, $this->ids, $this->pipeline()),
        );
    }

    /**
     * Each changeset the committer got, as "<command> <actor>".
     *
     * @return list<string>
     */
    public function changesets(): array
    {
        return array_map(static fn (PendingChangeset $pending): string => $pending->command->value.' '.$pending->envelope->actor->toString(), $this->committer->pending);
    }

    public function registry(): CompiledRegistry
    {
        return new CompiledRegistry(
            array_map(static fn (string $name): CommandEntry => new CommandEntry(new CommandName($name), 1, RenameProbe::class, 'cboxdk/cms'), self::COMMANDS),
            [],
            array_map(static fn (string $name): ActionEntry => new ActionEntry(RenameProbeAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName($name), 1, RenameProbe::class, []), self::QUERIES),
        );
    }

    private function pipeline(): CommandPipeline
    {
        $keys = $this->keys->session();
        $receipts = $this->receipts->session();
        $types = new FakeTypeCatalog;
        $reader = new CommittedRoles($this->reader, $this->committer);

        return new CommandPipeline(
            new FakeWriteActions([
                GrantBootstrapRole::class => $this->binding('access.bootstrap', new GrantBootstrapRoleAction(
                    $reader,
                    new CreateRoleAction($reader, new FakePermissionCatalog(self::COMMANDS, self::QUERIES)),
                    new AssignGrantAction($this->identity, $reader),
                )),
            ]),
            $this->identity,
            MaintenanceAuthorizer::forAccessBootstrap($this->installation),
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            new FakePublicPlacements,
            $this->committer,
            $keys,
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($keys, $receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(0)),
        );
    }

    private function binding(string $command, object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), 1, $action);
    }
}
