<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Actions\AssignGrantAction;
use Cbox\Cms\Core\Access\Actions\CreateRoleAction;
use Cbox\Cms\Core\Access\Actions\RevokeGrantAction;
use Cbox\Cms\Core\Access\Actions\SetRolePermissionsAction;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeGrantReader;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissionCatalog;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakePublicPlacements;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;

/**
 * grant.assign, grant.revoke, role.create and role.set_permissions through the command pipeline
 * with fakes (GUARDRAILS 9), over the tree of AccessWorld: ROOT, with NEWS, SPORT below it and
 * FOOTBALL below that, and CULTURE. The issuer is a staff actor holding the grants a test gives
 * it, decided by FakeCommandAuthorizer as the kernel's authorizer decides, the escalation guard
 * included; the GrantReader reaches every node unless a test says otherwise. The roles a test grants are added to the reader. The
 * PermissionCatalog knows the names of NAMES.
 */
final class GrantActionWorld
{
    public const string GRANT = '01936f5e-8a2b-7c3d-9e4f-000000000601';

    public const string ROLE = '01936f5e-8a2b-7c3d-9e4f-000000000602';

    /**
     * The command names the world's PermissionCatalog knows.
     *
     * @var list<string>
     */
    public const array NAMES = [
        'actor.deactivate',
        'entry.create',
        'entry.publish',
        'entry.revise',
        'grant.assign',
        'grant.revoke',
        'role.create',
        'role.set_permissions',
    ];

    /**
     * The query names the world's PermissionCatalog knows.
     *
     * @var list<string>
     */
    public const array QUERIES = ['grant.list', 'path.resolve', 'role.list'];

    public readonly FakeIdentity $identity;

    public readonly ActorId $issuer;

    public readonly FakePermissions $permissions;

    public FakeGrantReader $reader;

    public FakeChangesetCommitter $committer;

    public FakeCommandAuthorizer $authorizer;

    public FakePermissionCatalog $catalog;

    private int $calls = 0;

    private int $roles = 0;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->issuer = $this->identity->addActor(ActorClass::Staff)->id;
        $this->permissions = new FakePermissions(array_map(static fn (array $ids): NodePath => new NodePath(AccessWorld::path(...$ids)), [
            AccessWorld::ROOT => [AccessWorld::ROOT],
            AccessWorld::NEWS => [AccessWorld::ROOT, AccessWorld::NEWS],
            AccessWorld::SPORT => [AccessWorld::ROOT, AccessWorld::NEWS, AccessWorld::SPORT],
            AccessWorld::FOOTBALL => [AccessWorld::ROOT, AccessWorld::NEWS, AccessWorld::SPORT, AccessWorld::FOOTBALL],
            AccessWorld::CULTURE => [AccessWorld::ROOT, AccessWorld::CULTURE],
        ]));
        $this->reader = new FakeGrantReader(array_map(NodeId::fromString(...), [AccessWorld::ROOT, AccessWorld::NEWS, AccessWorld::SPORT, AccessWorld::FOOTBALL, AccessWorld::CULTURE]));
        $this->committer = new FakeChangesetCommitter;
        $this->catalog = new FakePermissionCatalog(self::NAMES, self::QUERIES);
        $this->authorizer = FakeCommandAuthorizer::granting($this->permissions, $this->catalog);
    }

    /**
     * Gives the issuer a grant of a role of its own, internal, with the permissions on the node.
     *
     * @param  list<string>  $permissions
     * @param  list<string>|null  $locales
     */
    public function hold(array $permissions, string $node, GrantEffect $effect = GrantEffect::Allow, ?array $locales = null): self
    {
        $this->permissions->grant($this->issuer, new Grant(
            RoleId::fromString(sprintf('01936f5e-8a2b-7c3d-9e4f-%012d', 700 + $this->roles++)),
            ClassificationAccess::Internal,
            $this->permissions->path($node),
            $effect,
            $this->locales($locales),
        ), $this->names($permissions));

        return $this;
    }

    /**
     * Adds the role ROLE with the permissions and ceiling to the reader, at version 1.
     *
     * @param  list<string>  $permissions
     */
    public function role(array $permissions, ClassificationAccess $ceiling = ClassificationAccess::Internal): RoleId
    {
        $role = RoleId::fromString(self::ROLE);
        $this->reader->addRole(new StoredRole($role, $ceiling, $this->names($permissions), AggregateVersion::first()));

        return $role;
    }

    /**
     * Adds the grant GRANT of ROLE to the actor on the node, at the version given.
     *
     * @param  list<string>|null  $locales
     */
    public function stored(ActorId $actor, string $node, GrantEffect $effect = GrantEffect::Allow, ?array $locales = null, int $version = 1, bool $ended = false): GrantId
    {
        $grant = GrantId::fromString(self::GRANT);
        $this->reader->addGrant(new StoredGrant($grant, $actor, RoleId::fromString(self::ROLE), NodeId::fromString($node), $effect, $this->locales($locales), new AggregateVersion($version), $ended));

        return $grant;
    }

    public function target(ActorClass $class = ActorClass::Staff, ActorState $state = ActorState::Active): ActorId
    {
        return $this->identity->addActor($class, $state)->id;
    }

    /**
     * grant.assign of ROLE to the actor on the node, with the id GRANT.
     *
     * @param  list<string>|null  $locales
     */
    public function assign(ActorId $actor, string $node, GrantEffect $effect = GrantEffect::Allow, ?array $locales = null): AssignGrant
    {
        return new AssignGrant(GrantId::fromString(self::GRANT), $actor, RoleId::fromString(self::ROLE), NodeId::fromString($node), $effect, $this->locales($locales));
    }

    public function run(AssignGrant|RevokeGrant|CreateRole|SetRolePermissions $command): WriteResult
    {
        $clock = new FakeClock;
        $keys = new FakeIdempotencyStore($clock)->session();
        $receipts = new FakeReceiptStore($clock)->session();
        $types = new FakeTypeCatalog;
        $pipeline = new CommandPipeline(
            new FakeWriteActions([
                AssignGrant::class => $this->binding('grant.assign', new AssignGrantAction($this->identity, $this->reader)),
                RevokeGrant::class => $this->binding('grant.revoke', new RevokeGrantAction($this->reader)),
                CreateRole::class => $this->binding('role.create', new CreateRoleAction($this->reader, $this->catalog)),
                SetRolePermissions::class => $this->binding('role.set_permissions', new SetRolePermissionsAction($this->reader, $this->catalog)),
            ]),
            $this->identity,
            $this->authorizer,
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
        $envelope = Envelope::external(
            IssuingSurface::Cli,
            EnvelopeIssuer::Human,
            $this->issuer,
            new IdempotencyKey('grant-command-'.++$this->calls),
            new CorrelationId('grant-command-correlation'),
        );

        return $pipeline->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->issuer, [], IssuerKind::Service, ClassificationAccess::Internal),
            [],
            ClassificationAccess::Internal,
        )));
    }

    /**
     * @return list<string> each read of the first changeset the committer got, as "<aggregate key> <version or ->"
     */
    public function reads(): array
    {
        return array_map(
            static fn (ReadVersion $read): string => $read->aggregate->aggregateKey().' '.($read->version->value ?? '-'),
            $this->committer->pending[0]->reads->reads ?? [],
        );
    }

    public function commitWith(CommitOutcome $outcome): void
    {
        $this->committer = new FakeChangesetCommitter($outcome);
    }

    /**
     * @param  list<string>|null  $locales
     * @return list<Locale>|null
     */
    private function locales(?array $locales): ?array
    {
        return $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales);
    }

    /**
     * @param  list<string>  $names
     * @return list<CommandName>
     */
    private function names(array $names): array
    {
        return array_map(static fn (string $name): CommandName => new CommandName($name), $names);
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
