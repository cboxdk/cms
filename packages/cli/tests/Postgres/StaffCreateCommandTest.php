<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Identity\Tests\Staff\StaffCommand;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * cms:staff:create on this checkout's test database (PRD 5.16, "Lokale konti"), after cms:install,
 * as the maintenance process runs it: the password typed twice, hidden, in the terminal. The staff
 * member is registered as the installation operator in the fixed order, so there are two
 * changesets after the genesis, actor.register then actor.activate; the actor is active and its
 * local account is a row of cms_identity.local_accounts that the identity connection reads and the
 * app role cannot. The command prints the actor's id. Usage errors, a breached password and an
 * email that has an account are refused with the catalog's exit codes and write nothing.
 */

const STAFF_NOW = '2026-03-10T12:00:00Z';

const STAFF_PASSWORD = 'a long and quite unusual sentence';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * Installs the operator at STAFF_NOW, with the breach check and cheap hashing bound for the test,
 * and gives the id cms:staff:create will make for its actor: the first of a FakeIdGenerator bound
 * after the install.
 */
function installedForStaff(bool $install = true): string
{
    $clock = new FakeClock(new DateTimeImmutable(STAFF_NOW));
    app()->instance(Clock::class, $clock);
    app()->instance(IdGenerator::class, new FakeIdGenerator(clock: $clock));
    app()->instance(BreachedPasswords::class, new FakeBreachedPasswords(new Password('Summer2026!Summer')));
    config(['cbox-cms.identity.passwords.argon2id' => ['memory_kib' => 1024, 'time' => 1]]);
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

    if ($install) {
        expect(app(Kernel::class)->call('cms:install'))->toBe(0);
    }

    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 11, clock: $clock));

    return new FakeIdGenerator(seed: 11, clock: $clock)->next()->value;
}

it('registers an active staff member in two changesets as the operator and prints its id', function (): void {
    $actor = installedForStaff();

    StaffCommand::pending(['--email' => 'Mette.Holm@example.com', '--name' => 'Mette Holm'])
        ->expectsQuestion('Password', STAFF_PASSWORD)
        ->expectsQuestion('Repeat the password', STAFF_PASSWORD)
        ->expectsOutput($actor)
        ->assertExitCode(0);

    $superuser = StorageTables::superuser();
    $operator = $superuser->table('installation')->value('operator_actor_id');
    $changesets = $superuser->table('changesets')->orderBy('changeset_id')->get(['command', 'actor_id'])
        ->map(static fn (object $row): string => implode(' ', array_map(static fn (mixed $value): string => is_string($value) ? $value : '', [$row->command ?? null, $row->actor_id ?? null])))
        ->all();
    $identity = DB::connection('pgsql_identity')->table(CredentialStore::table('local_accounts'))->where('actor_id', $actor)->first();

    expect($superuser->table('actors')->where('id', $actor)->first(['actor_class', 'state', 'version']))
        ->toEqual((object) ['actor_class' => 'staff', 'state' => 'active', 'version' => 2])
        ->and($changesets)->toBe([
            'installation.genesis '.(is_string($operator) ? $operator : ''),
            'actor.register '.(is_string($operator) ? $operator : ''),
            'actor.activate '.(is_string($operator) ? $operator : ''),
        ])
        ->and($identity->login ?? null)->toBe('mette.holm@example.com')
        ->and(is_string($hash = $identity->password_hash ?? null) && password_verify(STAFF_PASSWORD, $hash))->toBeTrue()
        ->and(static fn (): mixed => DB::connection()->table(CredentialStore::table('local_accounts'))->count())
        ->toThrow(QueryException::class, 'permission denied');
});

it('refuses a breached password and an email whose login has an account with exit 65, and writes nothing more', function (): void {
    installedForStaff();
    StaffCommand::pending(['--email' => 'mette@example.com', '--name' => 'Mette Holm'])
        ->expectsQuestion('Password', STAFF_PASSWORD)
        ->expectsQuestion('Repeat the password', STAFF_PASSWORD)
        ->assertExitCode(0);
    $before = StorageTables::superuser()->table('changesets')->count();

    StaffCommand::pending(['--email' => 'ole@example.com', '--name' => 'Ole Holm'])
        ->expectsQuestion('Password', 'Summer2026!Summer')
        ->expectsQuestion('Repeat the password', 'Summer2026!Summer')
        ->assertExitCode(65);
    StaffCommand::pending(['--email' => 'METTE@example.com', '--name' => 'Mette Again'])
        ->expectsQuestion('Password', STAFF_PASSWORD)
        ->expectsQuestion('Repeat the password', STAFF_PASSWORD)
        ->assertExitCode(65);

    expect(StorageTables::superuser()->table('changesets')->count())->toBe($before)
        ->and(StorageTables::superuser()->table('actors')->count())->toBe(2);
});

it('refuses before cms:install with the exit code of installation_operator_missing', function (): void {
    installedForStaff(install: false);

    StaffCommand::pending(['--email' => 'mette@example.com', '--name' => 'Mette Holm'])
        ->expectsQuestion('Password', STAFF_PASSWORD)
        ->expectsQuestion('Repeat the password', STAFF_PASSWORD)
        ->assertExitCode(78);

    expect(StorageTables::superuser()->table('actors')->count())->toBe(0);
});

it('refuses with exit 64, before asking for a password, a call without --email or --name or with an invalid email', function (array $options): void {
    installedForStaff();

    StaffCommand::pending($options)->assertExitCode(64);

    expect(StorageTables::superuser()->table('actors')->count())->toBe(1);
})->with([
    'no email' => [['--name' => 'Mette Holm']],
    'no name' => [['--email' => 'mette@example.com']],
    'an invalid email' => [['--email' => 'mette', '--name' => 'Mette Holm']],
]);

it('refuses with exit 64 two passwords that differ, and a call that is not interactive and has no --password-stdin', function (): void {
    installedForStaff();

    StaffCommand::pending(['--email' => 'mette@example.com', '--name' => 'Mette Holm'])
        ->expectsQuestion('Password', STAFF_PASSWORD)
        ->expectsQuestion('Repeat the password', STAFF_PASSWORD.'!')
        ->assertExitCode(64);
    StaffCommand::pending(['--email' => 'mette@example.com', '--name' => 'Mette Holm', '--no-interaction' => true])
        ->assertExitCode(64);

    expect(StorageTables::superuser()->table('actors')->count())->toBe(1);
});
