<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Identity\LocalAccounts\Domain\LocalConnection;
use Cbox\Cms\Identity\Tests\Staff\StaffCommand;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * No email address or password reaches a log, a span or an exception message (PRD 5.16, GUARDRAILS 5
 * and 6). The test captures every log entry and the FakeTelemetry records while a staff member is
 * registered with cms:staff:create, two registrations fail (a breached password, an email that has
 * an account), and the local connection on Postgres takes a login, a wrong password and an unknown
 * email; then it looks for the addresses and the passwords, in any case, in all of it.
 */

const SECRET_EMAIL = 'Secret.Person@example.com';

const SECRET_PASSWORD = 'a long and quite secret sentence';

const SECRET_BREACHED = 'Summer2026!Summer';

const SECRET_WRONG = 'a long and quite wrong sentence';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * Runs a login through the local connection and gives the message of its refusal, or the subject
 * of its assertion.
 */
function secretLogin(string $email, string $password): string
{
    $connection = app(LocalConnection::class);
    $started = $connection->start();

    try {
        return $connection->complete($started->pending, new SubmittedCredentials($started->pending->state->value, $email, $password))->subject->value;
    } catch (LoginRefused $refused) {
        return $refused->getMessage();
    }
}

it('keeps every email address and password out of the log, the telemetry and the messages of a login and a failed staff create', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $telemetry = new FakeTelemetry;
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $entry) use (&$logged): void {
        $logged[] = $entry->level.' '.$entry->message.' '.json_encode($entry->context);
    });
    app()->instance(Clock::class, $clock);
    app()->instance(IdGenerator::class, new FakeIdGenerator(clock: $clock));
    app()->instance(Telemetry::class, $telemetry);
    app()->instance(BreachedPasswords::class, new FakeBreachedPasswords(new Password(SECRET_BREACHED)));
    config(['cbox-cms.identity.passwords.argon2id' => ['memory_kib' => 1024, 'time' => 1]]);
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
    expect(app(Kernel::class)->call('cms:install'))->toBe(0);

    StaffCommand::pending(['--email' => SECRET_EMAIL, '--name' => 'Secret Person'])
        ->expectsQuestion('Password', SECRET_PASSWORD)
        ->expectsQuestion('Repeat the password', SECRET_PASSWORD)
        ->assertExitCode(0);
    StaffCommand::pending(['--email' => 'other.person@example.com', '--name' => 'Other Person'])
        ->expectsQuestion('Password', SECRET_BREACHED)
        ->expectsQuestion('Repeat the password', SECRET_BREACHED)
        ->doesntExpectOutputToContain('Summer2026')
        ->doesntExpectOutputToContain('other.person')
        ->assertExitCode(65);
    StaffCommand::pending(['--email' => SECRET_EMAIL, '--name' => 'Secret Person'])
        ->expectsQuestion('Password', SECRET_PASSWORD)
        ->expectsQuestion('Repeat the password', SECRET_PASSWORD)
        ->doesntExpectOutputToContain('secret.person')
        ->doesntExpectOutputToContain('Secret.Person')
        ->doesntExpectOutputToContain(SECRET_PASSWORD)
        ->assertExitCode(65);
    $messages = [
        secretLogin(SECRET_EMAIL, SECRET_PASSWORD),
        secretLogin(SECRET_EMAIL, SECRET_WRONG),
        secretLogin('nobody.here@example.com', SECRET_WRONG),
    ];

    $recorded = mb_strtolower(implode("\n", [
        ...$logged,
        ...$messages,
        print_r($telemetry->spans(), true),
        print_r($telemetry->counters(), true),
        print_r($telemetry->histograms(), true),
    ]));

    expect($telemetry->spans())->not->toBe([])
        ->and($messages[0])->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}\z/')
        ->and($messages[1])->toContain('The login was refused')
        ->and($messages[2])->toBe($messages[1]);

    foreach (['secret.person', 'other.person', 'nobody.here', '@example.com', SECRET_PASSWORD, SECRET_BREACHED, SECRET_WRONG] as $secret) {
        expect($recorded)->not->toContain(mb_strtolower($secret));
    }
});
