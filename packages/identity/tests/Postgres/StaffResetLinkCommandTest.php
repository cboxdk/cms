<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Identity\CredentialStore\Adapter\PostgresLocalCredentialStore;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\Tests\LocalAccounts\PostgresLocalAccounts;
use Cbox\Cms\Identity\Tests\Sessions\SessionWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;

/*
 * cms:staff:reset-link on this checkout's test database (PRD 5.16), as an operator runs it in the
 * maintenance process: it prints a link to the reset page of cbox-cms.identity.password_reset.url
 * with a token that the credential store keeps only as its SHA-256, expiring 60 minutes after the
 * Clock's time, and sends no mail. A login without a local account, an actor that is not active, an
 * actor the login policy keeps from a local login, a text that is not an email and a process without the owner connection are refused with the
 * catalog's exit codes and write nothing.
 */

const LINK_NOW = '2026-03-10T12:00:00Z';

const LINK_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0c2FsdA$9bSmXR8xsVQBq0xKC6hU3nPVUbKjsr6Ln0QGqkPZ+9I';

function linkAccount(string $email, ActorState $state = ActorState::Active): void
{
    $clock = new FakeClock(new DateTimeImmutable(LINK_NOW));
    $actor = new PostgresIdentitySeeder(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: crc32($email), clock: $clock))->addActor(ActorClass::Staff, $state);
    app(LocalCredentialStore::class)->bind($actor->id, new LoginIdentifier($email), new PasswordHash(LINK_HASH));
}

function sentMails(): int
{
    $transport = app(MailManager::class)->mailer()->getSymfonyTransport();

    return $transport instanceof ArrayTransport ? $transport->messages()->count() : -1;
}

function linkTokens(): int
{
    return DB::connection(PostgresLocalAccounts::CONNECTION)->table(CredentialStore::table(PostgresLocalCredentialStore::TOKENS))->count();
}

beforeEach(function (): void {
    $clock = new FakeClock(new DateTimeImmutable(LINK_NOW));
    app()->instance(Clock::class, $clock);
    app()->instance(LocalCredentialStore::class, new PostgresLocalCredentialStore(app(DatabaseManager::class), PostgresLocalAccounts::CONNECTION, $clock));
});

it('prints a link with a token the store keeps as its hash, and sends no mail', function (): void {
    linkAccount('mette.holm@example.com');

    $exit = app(Kernel::class)->call('cms:staff:reset-link', ['email' => ' Mette.Holm@Example.com ']);
    $lines = explode("\n", trim(app(Kernel::class)->output()));
    $token = PasswordResetToken::parse(substr($lines[0], strlen('http://localhost:8000/cms/reset-password/')));
    $stored = DB::connection(PostgresLocalAccounts::CONNECTION)->table(CredentialStore::table(PostgresLocalCredentialStore::TOKENS))->first(['token_hash', 'expires_at']);

    expect($exit)->toBe(0)
        ->and($lines[0])->toStartWith('http://localhost:8000/cms/reset-password/cms_pr_')
        ->and($lines[1])->toBe('The link expires at 2026-03-10T13:00:00Z and sets the password once.')
        ->and($token)->toBeInstanceOf(PasswordResetToken::class)
        ->and($stored->token_hash ?? null)->toBe($token?->hash())
        ->and(sentMails())->toBe(0);
});

it('refuses a login without a local account, an actor that is not active and a text that is not an email', function (string $email, int $exit, string $error): void {
    linkAccount('pending@example.com', ActorState::Pending);

    expect(app(Kernel::class)->call('cms:staff:reset-link', ['email' => $email]))->toBe($exit)
        ->and(app(Kernel::class)->output())->toContain($error)
        ->and(app(Kernel::class)->output())->not->toContain('@example.com')
        ->and(linkTokens())->toBe(0);
})->with([
    'no local account' => ['nobody@example.com', 67, '[local_account_missing]'],
    'pending actor' => ['pending@example.com', 77, '[actor_not_active]'],
    'not a login' => ['not an email', 64, 'needs the email address'],
]);

it('refuses an actor the login policy keeps from logging in locally', function (): void {
    linkAccount('mette.holm@example.com');
    app()->instance(LoginPolicy::class, SessionWorld::policy(['staff' => ['local_login' => false]]));

    expect(app(Kernel::class)->call('cms:staff:reset-link', ['email' => 'mette.holm@example.com']))->toBe(77)
        ->and(app(Kernel::class)->output())->toContain('[login_local_disabled]')
        ->and(app(Kernel::class)->output())->not->toContain('@example.com')
        ->and(linkTokens())->toBe(0);
});

it('runs only in the maintenance process', function (): void {
    linkAccount('mette.holm@example.com');
    config(['cbox-cms.database.owner_connection' => null]);

    expect(app(Kernel::class)->call('cms:staff:reset-link', ['email' => 'mette.holm@example.com']))->toBe(78)
        ->and(app(Kernel::class)->output())->toContain('[maintenance_process_required]')
        ->and(linkTokens())->toBe(0);
});
