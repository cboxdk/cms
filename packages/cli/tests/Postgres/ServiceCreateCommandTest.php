<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * cms:service:create on this checkout's test database (PRD 5.16), after cms:install, as the
 * maintenance process runs it: the service actor is registered as the installation operator with
 * the active staff actor responsible for it, then activated, two changesets after the genesis,
 * actor.register then actor.activate; the actor is active at version 2 with its profile, and the
 * command prints its id alone on standard output. A responsible person that is not an active
 * staff actor, a usage error, a process without the owner connection and a run before cms:install
 * are refused with the catalog's exit codes and write nothing.
 */

const SERVICE_NOW = '2026-03-10T12:00:00Z';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * Installs the operator at SERVICE_NOW, whose day the partitions cover, and seeds a staff actor in
 * the state given; the staff actor's id.
 */
function serviceWorld(ActorState $staff = ActorState::Active, bool $install = true): ActorId
{
    $clock = new FakeClock(new DateTimeImmutable(SERVICE_NOW));
    app()->instance(Clock::class, $clock);
    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 2701, clock: $clock));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

    if ($install) {
        expect(app(Kernel::class)->call('cms:install'))->toBe(0);
    }

    return new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 2702, clock: $clock))->addActor(ActorClass::Staff, $staff)->id;
}

/**
 * Runs cms:service:create in-process and gives its exit code and output.
 *
 * @param  array<array-key, mixed>  $options  as Kernel::call() takes them
 * @return array{int, string}
 */
function createService(array $options): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:service:create', $options);

    return [$status, $artisan->output()];
}

/**
 * Each changeset as "<command> <actor>", in order.
 *
 * @return list<string>
 */
function serviceChangesets(): array
{
    return array_values(StorageTables::superuser()->table('changesets')->orderBy('changeset_id')->get(['command', 'actor_id'])
        ->map(static fn (object $row): string => implode(' ', array_map(static fn (mixed $value): string => is_string($value) ? $value : '', (array) $row)))
        ->all());
}

it('registers and activates a service actor in two changesets as the operator, and prints its id', function (): void {
    $staff = serviceWorld();
    $superuser = StorageTables::superuser();
    $operator = $superuser->table('installation')->value('operator_actor_id');
    $operator = is_string($operator) ? $operator : '';

    [$status, $output] = createService(['--email' => 'importer@example.com', '--name' => 'Importer', '--responsible' => $staff->toString()]);
    $actor = ActorId::fromString(trim(explode("\n", trim($output), 2)[0]));
    $row = $superuser->table('actors')->where('id', $actor->toString())->first(['actor_class', 'state', 'version', 'credential_generation']);
    $profile = $superuser->table('actor_profiles')->where('actor_id', $actor->toString())->first(['display_name', 'email']);

    expect($status)->toBe(0, $output)
        ->and($row)->toEqual((object) ['actor_class' => 'service', 'state' => 'active', 'version' => 2, 'credential_generation' => 1])
        ->and($profile)->toEqual((object) ['display_name' => 'Importer', 'email' => 'importer@example.com'])
        ->and(serviceChangesets())->toBe([
            "installation.genesis {$operator}",
            "actor.register {$operator}",
            "actor.activate {$operator}",
        ]);
});

it('refuses a responsible person that is not an active staff actor with exit 65 and writes nothing', function (): void {
    $pending = serviceWorld(ActorState::Pending);
    $before = serviceChangesets();

    [$status, $output] = createService(['--email' => 'importer@example.com', '--name' => 'Importer', '--responsible' => $pending->toString()]);

    expect($status)->toBe(65, $output)
        ->and($output)->toContain('validation_failed at responsible')
        ->and($output)->not->toContain('importer@example.com')
        ->and(serviceChangesets())->toBe($before)
        ->and(StorageTables::superuser()->table('actors')->where('actor_class', 'service')->count())->toBe(1);
});

it('refuses with exit 64 a call without --email, --name or --responsible, or with a responsible person that is not a UUIDv7', function (array $options): void {
    serviceWorld();
    $before = serviceChangesets();

    [$status, $output] = createService($options);

    expect($status)->toBe(64, $output)
        ->and(serviceChangesets())->toBe($before);
})->with([
    'no email' => [['--name' => 'Importer', '--responsible' => '019cd79e-4600-7000-8000-000000002701']],
    'no name' => [['--email' => 'importer@example.com', '--responsible' => '019cd79e-4600-7000-8000-000000002701']],
    'no responsible' => [['--email' => 'importer@example.com', '--name' => 'Importer']],
    'an invalid email' => [['--email' => 'importer', '--name' => 'Importer', '--responsible' => '019cd79e-4600-7000-8000-000000002701']],
    'a responsible person that is not a UUIDv7' => [['--email' => 'importer@example.com', '--name' => 'Importer', '--responsible' => 'the-admin']],
]);

it('refuses before cms:install with installation_operator_missing, and a process without the owner connection with maintenance_process_required, both exit 78', function (): void {
    $staff = serviceWorld(install: false);
    $options = ['--email' => 'importer@example.com', '--name' => 'Importer', '--responsible' => $staff->toString()];

    [$missing, $missingOutput] = createService($options);
    config(['cbox-cms.database.owner_connection' => null]);
    [$noOwner, $noOwnerOutput] = createService($options);

    expect($missing)->toBe(78, $missingOutput)
        ->and($missingOutput)->toContain('installation_operator_missing')
        ->and($noOwner)->toBe(78, $noOwnerOutput)
        ->and($noOwnerOutput)->toContain('maintenance_process_required')
        ->and(StorageTables::superuser()->table('actors')->count())->toBe(1);
});
