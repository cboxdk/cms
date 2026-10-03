<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Maintenance\Domain\BootstrapRole;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * cms:access:bootstrap on this checkout's test database (PRD 5.10, 5.16), as the maintenance
 * process runs it after cms:install: the operator creates the bootstrap role and grants it to an
 * active staff actor on a node, in one access.bootstrap changeset of the maintenance issuer by the
 * operator. A second run is refused with access_bootstrap_done, and a run in the production
 * environment with access_bootstrap_production, both with their exit codes and without writing
 * anything. After the bootstrap the kernel's own pipeline, with PostgresCommandAuthorizer, lets the
 * bootstrapped actor assign a role on the node, which a staff member without a grant may not.
 */

const BOOTSTRAP_NOW = '2026-03-10T12:00:00Z';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * An installed operator, an active staff actor and a node below a site's root, at BOOTSTRAP_NOW,
 * whose day the partitions cover.
 *
 * @return array{ActorId, NodeId, FakeClock}
 */
function bootstrapWorld(ActorState $state = ActorState::Active): array
{
    $clock = new FakeClock(new DateTimeImmutable(BOOTSTRAP_NOW));
    app()->instance(Clock::class, $clock);
    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 2401, clock: $clock));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

    expect(app(Kernel::class)->call('cms:install'))->toBe(0);

    $connections = app(ConnectionResolverInterface::class);
    $staff = new PostgresIdentitySeeder($connections, $clock, new FakeIdGenerator(seed: 2402, clock: $clock))->addActor(ActorClass::Staff, $state)->id;
    $structure = new PostgresStructureFixtures($connections, $clock, new FakeIdGenerator(seed: 2403, clock: $clock));
    $node = $structure->node($structure->site('bootstrapped', [new Locale('en')])->root)->id;

    return [$staff, $node, $clock];
}

/**
 * Runs cms:access:bootstrap in-process and gives its exit code and output.
 *
 * @return array{int, string}
 */
function bootstrap(ActorId $actor, NodeId $node): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:access:bootstrap', ['actor' => $actor->toString(), 'node' => $node->toString()]);

    return [$status, $artisan->output()];
}

/**
 * Each changeset as "<command> <actor> <surface> <issuer kind>", in order.
 *
 * @return list<string>
 */
function bootstrapChangesets(): array
{
    return array_values(StorageTables::superuser()->table('changesets')->orderBy('changeset_id')->get(['command', 'actor_id', 'surface', 'issuer_kind'])
        ->map(static fn (object $row): string => implode(' ', array_map(static fn (mixed $value): string => is_string($value) ? $value : '', (array) $row)))
        ->all());
}

/**
 * Runs grant.assign through the kernel's pipeline, with the kernel's authorizer, as the staff
 * actor in the panel: a role with entry.create, granted to another staff actor on the node.
 */
function assignAs(ActorId $issuer, NodeId $node, FakeClock $clock, string $key): WriteResult
{
    $connections = app(ConnectionResolverInterface::class);
    $ids = new FakeIdGenerator(seed: crc32($key), clock: $clock);
    $target = new PostgresIdentitySeeder($connections, $clock, $ids)->addActor(ActorClass::Staff)->id;
    $role = new PostgresAccessFixtures($connections, $clock, $ids)->role('editor_'.$key, ClassificationAccess::Internal, [new CommandName('entry.create')]);
    $principal = new ActorPrincipal($issuer, [], IssuerKind::Human, IssuerKind::Human->maximumCeiling());
    $envelope = Envelope::external(IssuingSurface::Inertia, EnvelopeIssuer::Human, $issuer, new IdempotencyKey('assign-'.$key), new CorrelationId('assign-'.$key));

    return app(CommandPipeline::class)->run(new CommandCall(
        new AssignGrant(new GrantId(app(IdGenerator::class)->next()), $target, $role, $node, GrantEffect::Allow),
        $envelope,
        app(AccessContexts::class)->for($principal),
    ));
}

/**
 * @return list<string>
 */
function bootstrapErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

it('creates the bootstrap role and its grant in one changeset by the operator, and refuses a second run with access_bootstrap_done', function (): void {
    [$staff, $node] = bootstrapWorld();
    $superuser = StorageTables::superuser();
    $operator = $superuser->table('installation')->value('operator_actor_id');
    $operator = is_string($operator) ? $operator : '';

    [$status, $output] = bootstrap($staff, $node);
    $role = (array) $superuser->table('roles')->first();
    $grant = (array) $superuser->table('grants')->first();
    $permissions = $superuser->table('role_permissions')->orderBy('command')->pluck('command')->all();
    $expected = array_map(static fn (CommandName $name): string => $name->value, BootstrapRole::permissions(app(CompiledRegistry::class)));
    $changesets = bootstrapChangesets();

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('Created the bootstrap role administrator')
        ->and($output)->toContain('Granted the bootstrap role to the staff actor '.$staff->toString().' on the node '.$node->toString())
        ->and([$role['handle'] ?? null, $role['classification_ceiling'] ?? null])->toBe(['administrator', 'sensitive'])
        ->and($permissions)->toBe($expected)
        ->and($permissions)->toContain('grant.assign')
        ->and($permissions)->toContain('path.resolve')
        ->and([$grant['actor_id'] ?? null, $grant['role_id'] ?? null, $grant['node_id'] ?? null, $grant['effect'] ?? null, array_key_exists('locales', $grant) ? $grant['locales'] : 'missing'])
        ->toBe([$staff->toString(), $role['id'] ?? null, $node->toString(), 'allow', null])
        ->and($changesets)->toBe([
            "installation.genesis {$operator} maintenance system",
            "access.bootstrap {$operator} maintenance system",
        ])
        ->and($superuser->table('audit')->where('actor_id', $operator)->count())->toBe(2);

    [$again, $againOutput] = bootstrap($staff, $node);

    expect($again)->toBe(77, $againOutput)
        ->and($againOutput)->toContain('access_bootstrap_done')
        ->and(bootstrapChangesets())->toBe($changesets)
        ->and($superuser->table('roles')->count())->toBe(1)
        ->and($superuser->table('grants')->count())->toBe(1);
});

it('refuses a run in the production environment with access_bootstrap_production and writes nothing', function (): void {
    [$staff, $node] = bootstrapWorld();
    app()->detectEnvironment(static fn (): string => 'production');

    [$status, $output] = bootstrap($staff, $node);

    expect($status)->toBe(77, $output)
        ->and($output)->toContain('access_bootstrap_production')
        ->and(StorageTables::superuser()->table('roles')->count())->toBe(0)
        ->and(StorageTables::superuser()->table('grants')->count())->toBe(0)
        ->and(bootstrapChangesets())->toHaveCount(1);
});

it('lets the bootstrapped actor assign a role on the node through the kernel\'s authorizer, which a staff member without a grant may not', function (): void {
    [$staff, $node, $clock] = bootstrapWorld();
    $other = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 2406, clock: $clock))->addActor(ActorClass::Staff)->id;

    $before = assignAs($staff, $node, $clock, 'before');
    [$status, $output] = bootstrap($staff, $node);
    $after = assignAs($staff, $node, $clock, 'after');
    $ungranted = assignAs($other, $node, $clock, 'ungranted');

    expect(bootstrapErrors($before))->toBe(['unauthorized'])
        ->and($status)->toBe(0, $output)
        ->and(bootstrapErrors($after))->toBe([])
        ->and($after->outcome())->toBe(Outcome::Committed)
        ->and(bootstrapErrors($ungranted))->toBe(['unauthorized']);
});

it('refuses a pending staff actor with actor_not_active, a node that does not exist with validation_failed, and a process without the owner connection', function (): void {
    [$pending, $node] = bootstrapWorld(ActorState::Pending);

    [$notActive, $notActiveOutput] = bootstrap($pending, $node);
    [$noNode, $noNodeOutput] = bootstrap($pending, NodeId::fromString('019cd79e-4600-7000-8000-000000002499'));
    config(['cbox-cms.database.owner_connection' => null]);
    [$noOwner, $noOwnerOutput] = bootstrap($pending, $node);

    expect($notActive)->toBe(77, $notActiveOutput)
        ->and($notActiveOutput)->toContain('actor_not_active at actor')
        ->and($noNode)->toBe(65, $noNodeOutput)
        ->and($noNodeOutput)->toContain('validation_failed at node')
        ->and($noOwner)->toBe(78, $noOwnerOutput)
        ->and($noOwnerOutput)->toContain('maintenance_process_required')
        ->and(StorageTables::superuser()->table('roles')->count())->toBe(0)
        ->and(bootstrapChangesets())->toHaveCount(1);
});
