<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\PasswordReset;

use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Core\Egress\Domain\Dto\OutboundMail;
use Cbox\Cms\Core\Tests\Egress\Fakes\FakeMailGateway;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordPolicy;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\Dto\ThrottleLimit;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\PasswordReset\Actions\IssueResetLink;
use Cbox\Cms\Identity\PasswordReset\Actions\PruneResetTokens;
use Cbox\Cms\Identity\PasswordReset\Actions\RequestPasswordReset;
use Cbox\Cms\Identity\PasswordReset\Actions\ResetPassword;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetSettings;
use Cbox\Cms\Identity\PasswordReset\Domain\ResetPage;
use Cbox\Cms\Identity\Sessions\Actions\EndSessions;
use Cbox\Cms\Identity\Sessions\Actions\IssueSession;
use Cbox\Cms\Identity\Sessions\Domain\SessionCounters;
use Cbox\Cms\Identity\Tests\Login\Fakes\FakeLoginThrottle;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Identity\Tests\LoginPolicy\Fakes\FakeIdpLinks;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * A password reset over fakes (GUARDRAILS 9): LocalLoginWorld's actors, local accounts, Argon2id
 * hasher, sessions, login policy, FakeIdpLinks, local connection, telemetry and clock, with FakeBreachedPasswords
 * that knows BREACHED, FakeMailGateway, a throttle of the requests at the module's default reset
 * limits, and the reset page at PAGE with links that work the module's default 60 minutes.
 */
final class PasswordResetWorld
{
    public const string PAGE = 'https://cms.example.com/cms/reset-password';

    public const string NEW_PASSWORD = 'a new and rather long passphrase';

    public const string BREACHED = 'password1234567';

    public LocalLoginWorld $login;

    public FakeBreachedPasswords $breached;

    public FakeMailGateway $mail;

    public FakeLoginThrottle $throttle;

    public ResetSettings $settings;

    public FakeIdpLinks $links;

    public function __construct()
    {
        $this->login = new LocalLoginWorld;
        $this->breached = new FakeBreachedPasswords(new Password(self::BREACHED));
        $this->mail = new FakeMailGateway($this->login->telemetry);
        $this->throttle = new FakeLoginThrottle(new LoginThrottleSettings(new ThrottleLimit(3, 3600), new ThrottleLimit(20, 3600)), $this->login->clock);
        $this->links = new FakeIdpLinks;
        $this->settings = new ResetSettings(ResetSettings::DEFAULT_MINUTES, new ResetPage(self::PAGE));
    }

    public function links(): IssueResetLink
    {
        return new IssueResetLink($this->login->accounts, $this->policy(), $this->settings, $this->login->clock);
    }

    public function request(): RequestPasswordReset
    {
        return new RequestPasswordReset($this->links(), $this->throttle, $this->mail, $this->settings, $this->login->telemetry);
    }

    public function reset(): ResetPassword
    {
        $counters = new SessionCounters($this->login->telemetry);

        return new ResetPassword(
            $this->login->accounts,
            new PasswordPolicy($this->breached),
            $this->login->hasher,
            $this->login->connection,
            $this->policy(),
            new EndSessions($this->login->sessions, $counters),
            new IssueSession($this->login->sessions, $this->login->clock, $counters),
            $this->login->telemetry,
        );
    }

    /**
     * The login policy over the world's policy, actors and IdP links.
     */
    public function policy(): CheckLoginPolicy
    {
        return new CheckLoginPolicy($this->login->policy, $this->login->identity, $this->links, $this->login->telemetry);
    }

    public function prune(): PruneResetTokens
    {
        return new PruneResetTokens($this->login->accounts, $this->login->clock);
    }

    /**
     * Binds the world in a container as LocalLoginWorld::into() does, with the reset's actions over
     * the world's fakes, so the panel's pages run through them.
     */
    public function into(Container $container): void
    {
        $this->login->into($container);
        $container->instance(ResetSettings::class, $this->settings);
        $container->instance(RequestPasswordReset::class, $this->request());
        $container->instance(ResetPassword::class, $this->reset());
    }

    /**
     * The token of the link in the last mail sent.
     */
    public function mailedToken(): string
    {
        $sent = $this->mail->sent();
        $last = $sent === [] ? throw new RuntimeException('No mail was sent.') : $sent[count($sent) - 1];

        return self::tokenOf($last);
    }

    public static function tokenOf(OutboundMail $mail): string
    {
        if (preg_match('~'.preg_quote(self::PAGE, '~').'/('.preg_quote(PasswordResetToken::PREFIX, '~').'[0-9a-f]{72})~', $mail->text, $match) !== 1) {
            throw new RuntimeException('The mail holds no reset link.');
        }

        return $match[1];
    }
}
