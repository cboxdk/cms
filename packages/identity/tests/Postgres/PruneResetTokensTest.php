<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Identity\CredentialStore\Adapter\PostgresLocalCredentialStore;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Identity\Tests\LocalAccounts\PostgresLocalAccounts;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/*
 * cms:identity:prune on this checkout's test database (PRD 5.16), as the maintenance process's
 * scheduler runs it: it removes exactly the reset tokens of cms_identity.password_reset_tokens that
 * were used, or expired, more than 24 hours before the Clock's time, through the identity
 * connection, and keeps a token that is still usable and one used or expired within the 24 hours.
 * It runs only in the maintenance process.
 */

const PRUNE_START = '2026-03-10T12:00:00.000000Z';

const PRUNE_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0c2FsdA$9bSmXR8xsVQBq0xKC6hU3nPVUbKjsr6Ln0QGqkPZ+9I';

const PRUNE_NEW_HASH = '$argon2id$v=19$m=65536,t=4,p=1$b3RoZXJvdGhlcm90aGVy$8M0yTqHfV5d9yX4RJ2pa6N3l6v0w1A3SEbSQ1gNfj2M';

/**
 * A token of a new account, issued at the harness's time, that expires after the minutes.
 */
function pruneToken(PostgresLocalAccounts $accounts, string $login, int $minutes): PasswordResetToken
{
    $actor = $accounts->actor();
    $accounts->store()->bind($actor, new LoginIdentifier($login), new PasswordHash(PRUNE_HASH));

    return $accounts->store()->issueResetToken($actor, $accounts->clock()->now()->add(new DateInterval(sprintf('PT%dM', $minutes))));
}

/**
 * The SHA-256 of each token the table holds, sorted.
 *
 * @return list<string>
 */
function storedTokens(): array
{
    $hashes = DB::connection(PostgresLocalAccounts::CONNECTION)->table(CredentialStore::table(PostgresLocalCredentialStore::TOKENS))->pluck('token_hash')->all();
    $hashes = array_values(array_filter($hashes, is_string(...)));
    sort($hashes);

    return $hashes;
}

/**
 * @param  list<PasswordResetToken>  $tokens
 * @return list<string>
 */
function hashesOf(array $tokens): array
{
    $hashes = array_map(static fn (PasswordResetToken $token): string => $token->hash(), $tokens);
    sort($hashes);

    return $hashes;
}

it('removes exactly the tokens used or expired more than 24 hours ago', function (): void {
    $clock = new FakeClock(new DateTimeImmutable(PRUNE_START));
    $accounts = new PostgresLocalAccounts($clock);
    app()->instance(Clock::class, $clock);
    app()->instance(LocalCredentialStore::class, $accounts->store());

    $usedLongAgo = pruneToken($accounts, 'used.long.ago@example.org', 60);
    $expiredLongAgo = pruneToken($accounts, 'expired.long.ago@example.org', 60);
    $clock->advance(new DateInterval('PT5M'));
    $accounts->store()->resetPassword($usedLongAgo, new PasswordHash(PRUNE_NEW_HASH));
    $clock->set(new DateTimeImmutable('2026-03-11T08:00:00Z'));
    $expiredRecently = pruneToken($accounts, 'expired.recently@example.org', 60);
    $usedRecently = pruneToken($accounts, 'used.recently@example.org', 60);
    $clock->advance(new DateInterval('PT10M'));
    $accounts->store()->resetPassword($usedRecently, new PasswordHash(PRUNE_NEW_HASH));
    $clock->set(new DateTimeImmutable('2026-03-11T13:30:00Z'));
    $usable = pruneToken($accounts, 'usable@example.org', 60);

    expect(storedTokens())->toBe(hashesOf([$usedLongAgo, $expiredLongAgo, $expiredRecently, $usedRecently, $usable]))
        ->and(app(Kernel::class)->call('cms:identity:prune'))->toBe(0)
        ->and(app(Kernel::class)->output())->toBe("Removed 2 password reset tokens used or expired more than 24 hours ago.\n")
        ->and(storedTokens())->toBe(hashesOf([$expiredRecently, $usedRecently, $usable]))
        ->and(app(Kernel::class)->call('cms:identity:prune'))->toBe(0)
        ->and(storedTokens())->toBe(hashesOf([$expiredRecently, $usedRecently, $usable]));

    // 24 hours after 08:30: the token used at 08:10 goes, though it expires only at 09:00.
    $clock->set(new DateTimeImmutable('2026-03-12T08:30:00Z'));

    expect(app(Kernel::class)->call('cms:identity:prune'))->toBe(0)
        ->and(storedTokens())->toBe(hashesOf([$expiredRecently, $usable]));
});

it('runs only in the maintenance process', function (): void {
    config(['cbox-cms.database.owner_connection' => null]);

    expect(app(Kernel::class)->call('cms:identity:prune'))->toBe(78)
        ->and(app(Kernel::class)->output())->toContain('[maintenance_process_required] cms:identity:prune runs only in the maintenance process');
});

it('is scheduled every hour in the maintenance process', function (): void {
    $events = app(Schedule::class)->events();
    $prune = array_values(array_filter($events, static fn (Event $event): bool => str_contains((string) $event->command, 'cms:identity:prune')));

    expect($prune)->toHaveCount(1)
        ->and($prune[0]->expression)->toBe('0 * * * *');
});
