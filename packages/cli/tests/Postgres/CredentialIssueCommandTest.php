<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Cli\Boundary\CliCredential;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
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
 * cms:credential:issue on this checkout's test database (PRD 5.16), as the maintenance process
 * runs it: a service credential written as the owner role for an active service actor, whose token
 * is printed once, alone on standard output, and verified by the container's CredentialVerifier
 * to that actor with the issuer kind service, the ceiling given and an expiry the given days from
 * now; the store keeps only the hash. The token is the credential of cms:run, whose call reaches the
 * pipeline with it: a revise of an entry that does not exist ends in version_conflict, past the
 * verifier. A staff actor, a pending service actor, a usage error and a process without the owner
 * connection are refused with the catalog's exit codes, and nothing is issued.
 */

const ISSUE_NOW = '2026-03-10T12:00:00Z';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * A FakeClock at ISSUE_NOW and a seeded actor of the class and state given; its id.
 */
function issueWorld(ActorClass $class = ActorClass::Service, ActorState $state = ActorState::Active): ActorId
{
    $clock = new FakeClock(new DateTimeImmutable(ISSUE_NOW));
    app()->instance(Clock::class, $clock);
    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 2801, clock: $clock));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

    return new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 2802, clock: $clock))->addActor($class, $state)->id;
}

/**
 * The first line of a command's output: what it printed alone on standard output, before the
 * notice on standard error, which the console's buffer holds too.
 */
function firstLine(string $output): string
{
    return trim(explode("\n", trim($output), 2)[0]);
}

/**
 * Runs cms:credential:issue in-process and gives its exit code and output.
 *
 * @param  array<array-key, mixed>  $options  as Kernel::call() takes them
 * @return array{int, string}
 */
function issueCredential(string $actor, array $options = []): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:credential:issue', ['actor' => $actor, ...$options]);

    return [$status, $artisan->output()];
}

it('issues a credential the verifier verifies to the service actor with the ceiling and the expiry, and prints the token once', function (): void {
    $service = issueWorld();

    [$status, $output] = issueCredential($service->toString(), ['--ceiling' => 'confidential', '--days' => '30']);
    $token = new TransportCredential(firstLine($output));
    $principal = app(CredentialVerifier::class)->verify($token);
    $row = StorageTables::superuser()->table('service_credentials')->where('actor_id', $service->toString())->first(['secret_hash', 'credential_generation', 'issuer_kind', 'classification_ceiling', 'expires_at']);

    expect($status)->toBe(0, $output)
        ->and(firstLine($output))->toStartWith(ServiceCredentialToken::PREFIX)
        ->and($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal ? $principal->actor->equals($service) : null)->toBeTrue()
        ->and($principal instanceof ActorPrincipal ? [$principal->issuerKind, $principal->ceiling, $principal->onBehalfOf] : null)->toBe([IssuerKind::Service, ClassificationAccess::Confidential, []])
        ->and($row?->secret_hash)->toBe(ServiceCredentialToken::parse($token)->hash())
        ->and([$row?->credential_generation, $row?->issuer_kind, $row?->classification_ceiling])->toBe([1, 'service', 'confidential'])
        ->and(is_string($row?->expires_at) ? new DateTimeImmutable($row->expires_at)->format(DATE_ATOM) : null)->toBe('2026-04-09T12:00:00+00:00')
        ->and(substr_count($output, ServiceCredentialToken::PREFIX))->toBe(1);
});

it('issues the credential cms:run runs with: a command as that service actor passes the verifier and reaches the pipeline', function (): void {
    $service = issueWorld();

    [$status, $output] = issueCredential($service->toString());
    config()->set(CliCredential::CONFIG_KEY, firstLine($output));
    $kernel = app(Kernel::class);
    $run = $kernel->call('cms:run', ['name' => 'entry.revise', 'version' => '1', 'document' => '{"entry":"019cd79e-4600-7000-8000-000000002811","fields":{},"version":1}', '--idempotency-key' => 'issued-run', '--json' => true]);
    $problem = json_decode(trim($kernel->output()), true, 64, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0, $output)
        ->and($run)->toBe(65)
        ->and(is_array($problem) ? $problem['code'] ?? null : null)->toBe('version_conflict')
        ->and(is_array($problem) ? $problem['code'] ?? null : null)->not->toStartWith('credential_');
});

it('refuses a staff actor with validation_failed and a pending service actor with actor_not_active, and issues nothing', function (): void {
    $staff = issueWorld(ActorClass::Staff);

    [$staffStatus, $staffOutput] = issueCredential($staff->toString());
    $pending = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), app(Clock::class), new FakeIdGenerator(seed: 2803, clock: app(Clock::class)))->addActor(ActorClass::Service, ActorState::Pending)->id;
    [$pendingStatus, $pendingOutput] = issueCredential($pending->toString());
    [$missingStatus, $missingOutput] = issueCredential('019cd79e-4600-7000-8000-000000002899');

    expect($staffStatus)->toBe(65, $staffOutput)
        ->and($staffOutput)->toContain('validation_failed at actor')
        ->and($pendingStatus)->toBe(77, $pendingOutput)
        ->and($pendingOutput)->toContain('actor_not_active')
        ->and($missingStatus)->toBe(65, $missingOutput)
        ->and(StorageTables::superuser()->table('service_credentials')->count())->toBe(0);
});

it('refuses with exit 64 an actor that is not a UUIDv7, a ceiling that is not a level and days outside 1 to 365', function (string $actor, array $options): void {
    issueWorld();

    [$status] = issueCredential($actor, $options);

    expect($status)->toBe(64)
        ->and(StorageTables::superuser()->table('service_credentials')->count())->toBe(0);
})->with([
    'not a UUIDv7' => ['the-importer', []],
    'a ceiling that is not a level' => ['019cd79e-4600-7000-8000-000000002811', ['--ceiling' => 'secret']],
    'zero days' => ['019cd79e-4600-7000-8000-000000002811', ['--days' => '0']],
    'too many days' => ['019cd79e-4600-7000-8000-000000002811', ['--days' => '366']],
    'days that are not a number' => ['019cd79e-4600-7000-8000-000000002811', ['--days' => 'ninety']],
]);

it('refuses a process without the owner connection with maintenance_process_required, exit 78, and issues nothing', function (): void {
    $service = issueWorld();
    config(['cbox-cms.database.owner_connection' => null]);

    [$status, $output] = issueCredential($service->toString());

    expect($status)->toBe(78, $output)
        ->and($output)->toContain('maintenance_process_required')
        ->and(StorageTables::superuser()->table('service_credentials')->count())->toBe(0);
});
