<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Identity\Login\Adapter\ValkeyLoginThrottle;
use Cbox\Cms\Identity\PasswordReset\Actions\RequestPasswordReset;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequest;
use Cbox\Cms\Identity\Tests\LocalAccounts\PostgresLocalAccounts;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use DateTimeImmutable;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;

/*
 * The request for a password reset link as the container builds it (PRD 5.16), on this checkout's
 * test database, real Valkey and the workbench's array mailer: its throttle is the login throttle's
 * script under a prefix of its own with the limits of cbox-cms.identity.password_reset.throttle, so
 * the fourth request for one email within the hour mails nothing, and a reset request never counts
 * against the login throttle. No key holds the email.
 */

it('mails at most three links an hour for one email and counts apart from the logins', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $accounts = new PostgresLocalAccounts($clock);
    app()->instance(Clock::class, $clock);
    app()->instance(LocalCredentialStore::class, $accounts->store());
    $actor = $accounts->actor();
    $accounts->store()->bind($actor, new LoginIdentifier('mette.holm@example.com'), new PasswordHash('$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0c2FsdA$9bSmXR8xsVQBq0xKC6hU3nPVUbKjsr6Ln0QGqkPZ+9I'));

    foreach (range(1, 4) as $request) {
        expect(app(RequestPasswordReset::class)->request(new ResetRequest('mette.holm@example.com', '192.0.2.30'))->taken)->toBeTrue();
    }

    $transport = app(MailManager::class)->mailer()->getSymfonyTransport();
    $keys = implode(' ', app(ValkeyRun::class)->keys());

    expect($transport)->toBeInstanceOf(ArrayTransport::class)
        ->and($transport instanceof ArrayTransport ? $transport->messages()->count() : null)->toBe(3)
        ->and($keys)->toContain(ValkeyLoginThrottle::RESET_KEY.'identifier:'.hash('sha256', 'mette.holm@example.com'))
        ->and($keys)->not->toContain(ValkeyLoginThrottle::KEY)
        ->and($keys)->not->toContain('mette.holm');
});
