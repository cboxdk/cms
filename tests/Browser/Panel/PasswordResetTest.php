<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Identity\PasswordReset\Domain\ResetMail;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Pest\Browser\Api\PendingAwaitablePage;
use PHPUnit\Framework\Assert;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/*
 * Resetting a password in the panel in Chromium (PRD 5.16, GUARDRAILS 6, 8 and 9), against the
 * build `composer panel:build` writes, this checkout's test database and Valkey: a member of staff
 * follows the link on the login page, asks for a reset link, reads it from the workbench's array
 * mailer in the in-process application, sets a new password on the page the link opens and lands
 * on the start page, signs out and signs in with the new password; the old one no longer works, and
 * the link does not work a second time. Every page makes the shared assertions.
 */

const RESET_PANEL_EMAIL = 'mette.holm@example.com';

const RESET_OLD_PASSWORD = 'correct horse battery staple';

const RESET_NEW_PASSWORD = 'a fresh and fairly long passphrase';

beforeEach(function (): void {
    app(Repository::class)->set('session.driver', 'redis');
    app(Repository::class)->set('session.connection', 'default');
    app()->instance(BreachedPasswords::class, new FakeBreachedPasswords);

    $clock = new FakeClock;
    $actor = new PostgresIdentitySeeder(app(DatabaseManager::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff, ActorState::Active);

    app(LocalCredentialStore::class)->bind($actor->id, new LoginIdentifier(RESET_PANEL_EMAIL), app(PasswordHasher::class)->hash(new Password(RESET_OLD_PASSWORD)));
});

/**
 * The mails the workbench's array mailer holds.
 *
 * @return list<Email>
 */
function mailedResets(): array
{
    $transport = app(MailManager::class)->mailer()->getSymfonyTransport();
    Assert::assertInstanceOf(ArrayTransport::class, $transport);

    $mails = [];

    foreach ($transport->messages() as $sent) {
        $message = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

        if ($message instanceof Email) {
            $mails[] = $message;
        }
    }

    return $mails;
}

/**
 * The path of the reset link in the one mail sent, below the panel the browser test serves.
 */
function resetPath(): string
{
    $mails = mailedResets();

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getSubject())->toBe(ResetMail::SUBJECT)
        ->and(array_map(static fn (Address $address): string => $address->getAddress(), $mails[0]->getTo()))->toBe([RESET_PANEL_EMAIL]);

    preg_match('~https?://[^/\s]+(/cms/reset-password/cms_pr_[0-9a-f]{72})~', (string) $mails[0]->getTextBody(), $link);

    return $link[1] ?? Assert::fail('The mail holds no reset link.');
}

it('asks for a link, sets a new password with it and signs in with the new password', function (): void {
    $page = visit('/cms/login');

    PanelPage::assertPage($page, ['panel.login.title', 'panel.login.forgot']);

    $page->click(PanelPage::text('panel.login.forgot'));

    $page->assertPathIs('/cms/forgot-password');
    PanelPage::assertPage($page, ['panel.forgot.title', 'panel.forgot.description', 'panel.forgot.email', 'panel.forgot.back']);

    $page->type('email', 'Mette.Holm@Example.com')
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.forgot.requested' => ['minutes' => 60]]);
    $page->assertPathIs('/cms/forgot-password');

    $path = resetPath();
    $page->navigate($path);

    PanelPage::assertPage($page, ['panel.reset.title', 'panel.reset.description', 'panel.reset.password', 'panel.reset.hint']);

    $page->type('password', 'too short')
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.reset.too_short']);

    $page->type('password', RESET_NEW_PASSWORD)
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.home.body', 'panel.home.sign_out']);
    $page->assertPathIs('/cms');

    $page->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.login.reason.signed_out']);

    $page->type('email', RESET_PANEL_EMAIL)
        ->type('password', RESET_OLD_PASSWORD)
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.login.failed']);

    $page->type('password', RESET_NEW_PASSWORD)
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.home.body']);
    $page->assertPathIs('/cms');

    $page->navigate($path);

    PanelPage::assertPage($page, ['panel.reset.title', 'panel.reset.password']);

    $page->type('password', 'yet another long passphrase')
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.reset.invalid', 'panel.reset.request_new']);
});

it('shows a link that is no token as one that no longer works, with the way to a new one', function (): void {
    $page = visit('/cms/reset-password/not-a-token');

    PanelPage::assertPage($page, ['panel.reset.title', 'panel.reset.invalid', 'panel.reset.request_new', 'panel.reset.back']);

    $page->click(PanelPage::text('panel.reset.request_new'));

    $page->assertPathIs('/cms/forgot-password');
    PanelPage::assertPage($page, ['panel.forgot.title']);
});

/**
 * @param  list<string>  $texts
 */
function assertResetPage(mixed $page, array $texts): void
{
    expect($page)->toBeInstanceOf(PendingAwaitablePage::class);
    assert($page instanceof PendingAwaitablePage);

    PanelPage::assertPage($page, $texts);
}

it('shows the pages of the reset in the dark theme and on a phone with the same assertions', function (callable $visit, array $texts): void {
    assertResetPage($visit(), array_values(array_filter($texts, is_string(...))));
})->with([
    'asking for a link, dark' => [fn (): PendingAwaitablePage => visit('/cms/forgot-password')->inDarkMode(), ['panel.forgot.title', 'panel.forgot.back']],
    'asking for a link, phone' => [fn (): PendingAwaitablePage => visit('/cms/forgot-password')->on()->mobile()->inLightMode(), ['panel.forgot.title', 'panel.forgot.back']],
    'a link that no longer works, dark' => [fn (): PendingAwaitablePage => visit('/cms/reset-password/not-a-token')->inDarkMode(), ['panel.reset.invalid', 'panel.reset.request_new']],
    'a link that no longer works, phone' => [fn (): PendingAwaitablePage => visit('/cms/reset-password/not-a-token')->on()->mobile()->inLightMode(), ['panel.reset.invalid', 'panel.reset.request_new']],
]);
