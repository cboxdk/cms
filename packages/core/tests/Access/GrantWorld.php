<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Actions\AssignGrantAction;
use Cbox\Cms\Core\Access\Actions\CreateRoleAction;
use Cbox\Cms\Core\Access\Actions\RevokeGrantAction;
use Cbox\Cms\Core\Access\Actions\SetRolePermissionsAction;
use Cbox\Cms\Core\Access\Adapter\GrantAssignedWriter;
use Cbox\Cms\Core\Access\Adapter\GrantRevokedWriter;
use Cbox\Cms\Core\Access\Adapter\GrantRoleContentChangedWriter;
use Cbox\Cms\Core\Access\Adapter\PostgresGrantReader;
use Cbox\Cms\Core\Access\Adapter\PostgresGrantSlotLock;
use Cbox\Cms\Core\Access\Adapter\PostgresGrantVersionLock;
use Cbox\Cms\Core\Access\Adapter\PostgresRoleGrantsLock;
use Cbox\Cms\Core\Access\Adapter\PostgresRoleHandleLock;
use Cbox\Cms\Core\Access\Adapter\PostgresRoleVersionLock;
use Cbox\Cms\Core\Access\Adapter\RoleCreatedWriter;
use Cbox\Cms\Core\Access\Adapter\RolePermissionsSetWriter;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Adapter\SavepointRefusal;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissionCatalog;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeAffectedProjections;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * grant.assign, grant.revoke, role.create and role.set_permissions on Postgres (PRD 5.10, 6.4): the
 * real command pipeline as the app role, with the kernel's command authorizer and escalation
 * guard, the grant reader, the commit with the locks of an actor, a grant, a role, a grant's slot,
 * a role's handle and a role's set of grants, and the writers. The PermissionCatalog knows the
 * names of GrantActionWorld::NAMES. A call runs with
 * the access context the kernel compiles for the principal from its grants.
 */
final readonly class GrantWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public FakeClock $clock;

    private ConnectionResolverInterface $connections;

    private FakeIdGenerator $ids;

    public function __construct()
    {
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $this->connections = app(ConnectionResolverInterface::class);
        $this->ids = new FakeIdGenerator(seed: 61, clock: $this->clock);
    }

    /**
     * Runs the command in the panel as the principal, with the key given.
     */
    public function run(ActorPrincipal $by, AssignGrant|RevokeGrant|CreateRole|SetRolePermissions $command, string $key): WriteResult
    {
        $envelope = Envelope::external(
            IssuingSurface::Inertia,
            EnvelopeIssuer::Human,
            $by->actor,
            new IdempotencyKey($key),
            new CorrelationId('grant-correlation'),
        );

        return $this->pipeline()->run(new CommandCall($command, $envelope, app(AccessContexts::class)->for($by)));
    }

    private function pipeline(): CommandPipeline
    {
        $types = new FakeTypeCatalog;
        $directory = new PostgresActorDirectory($this->connections);
        $receipts = new PostgresReceiptStore($this->connections, $this->clock);
        $reader = new PostgresGrantReader($this->connections);
        $catalog = new FakePermissionCatalog(GrantActionWorld::NAMES, GrantActionWorld::QUERIES);

        return new CommandPipeline(
            new FakeWriteActions([
                AssignGrant::class => $this->binding('grant.assign', new AssignGrantAction($directory, $reader)),
                RevokeGrant::class => $this->binding('grant.revoke', new RevokeGrantAction($reader)),
                CreateRole::class => $this->binding('role.create', new CreateRoleAction($reader, $catalog)),
                SetRolePermissions::class => $this->binding('role.set_permissions', new SetRolePermissionsAction($reader, $catalog)),
            ]),
            $directory,
            app(CommandAuthorizer::class),
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            new PostgresChangesetCommitter(
                $this->connections,
                $this->clock,
                $this->ids,
                new VersionLocks(
                    new PostgresActorVersionLock($this->connections),
                    new PostgresGrantVersionLock($this->connections),
                    new PostgresRoleVersionLock($this->connections),
                    new PostgresGrantSlotLock($this->connections),
                    new PostgresRoleHandleLock($this->connections),
                    new PostgresRoleGrantsLock($this->connections),
                ),
                new MutationWriters(
                    new GrantAssignedWriter($this->connections),
                    new GrantRevokedWriter($this->connections),
                    new RoleCreatedWriter($this->connections),
                    new RolePermissionsSetWriter($this->connections),
                    new GrantRoleContentChangedWriter($this->connections),
                ),
                new FakeAffectedProjections,
                $receipts,
            ),
            new PostgresIdempotencyStore($this->connections, $this->clock),
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(200)),
            new ConnectionCommandTransaction($this->connections, new SavepointRefusal),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new SystemPacing, new WaitSettings(0)),
        );
    }

    /**
     * The binding of a command, version 1, as the registry gives it: it takes any write action.
     */
    private function binding(string $command, object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), 1, $action);
    }
}
