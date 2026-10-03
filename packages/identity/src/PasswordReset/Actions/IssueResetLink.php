<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalAccountMissing;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginPolicyRefused;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\IssuedResetLink;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetLinkOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetSettings;
use DateInterval;

/**
 * Issues a password reset link for a login (PRD 5.16), the step both the panel's page and
 * cms:staff:reset-link take. Only a known local account whose actor the login policy would let log
 * in by password reset gets one (CheckLoginPolicy::admitLocal()): the actor is active, its class's
 * policy lists the method password_reset and has local login on, and it is linked to no
 * authoritative connection (invariant 38); any other is refused with the policy's code. The store
 * keeps the SHA-256 of a new random token that expires the settings' minutes after the Clock's
 * time, and the link is the reset page's address with the token. It sends nothing.
 */
#[Internal]
final readonly class IssueResetLink
{
    public function __construct(
        private LocalCredentialStore $store,
        private CheckLoginPolicy $policy,
        private ResetSettings $settings,
        private Clock $clock,
    ) {}

    public function issue(LoginIdentifier $login): ResetLinkOutcome
    {
        $account = $this->store->find($login);

        if (! $account instanceof LocalAccount) {
            return ResetLinkOutcome::refused(ErrorCode::LocalAccountMissing);
        }

        try {
            $this->policy->admitLocal($account->actor, LoginMethod::PasswordReset);
        } catch (LoginPolicyRefused $refused) {
            return ResetLinkOutcome::refused(ErrorCode::from($refused->reason->value));
        }

        $expiresAt = $this->clock->now()->add(new DateInterval(sprintf('PT%dM', $this->settings->tokenMinutes)));

        try {
            $token = $this->store->issueResetToken($account->actor, $expiresAt);
        } catch (LocalAccountMissing) {
            // The account was removed since it was found.
            return ResetLinkOutcome::refused(ErrorCode::LocalAccountMissing);
        }

        return ResetLinkOutcome::issued(new IssuedResetLink($account->actor, $account->login, $this->settings->page->link($token), $expiresAt));
    }
}
