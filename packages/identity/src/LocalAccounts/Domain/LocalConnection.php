<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\LoginConnection;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginFlow;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\LoginResponse;
use Cbox\Cms\Contracts\Identity\Login\LoginStarted;
use Cbox\Cms\Contracts\Identity\Login\LoginState;
use Cbox\Cms\Contracts\Identity\Login\PendingLogin;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Identity\LocalAccounts\Domain\Dto\LocalLogin;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Override;

/**
 * The local connection (PRD 5.16): the local accounts of the LocalCredentialStore, behind the
 * LoginConnection contract, of the flow Direct and with the connection id `local`.
 *
 * start() gives a pending login with a fresh state for the login form. complete() checks that the
 * response belongs to the pending login, then verifies the email and password the form took:
 *
 * - the identifier is the email in lower case (LoginIdentifier::typed()); the account is found by
 *   it, and the password is verified against its hash once;
 * - an identifier without an account, one that is not an identifier, and an empty password are
 *   verified against the hasher's fixed dummy hash once, so they cost what a wrong password costs
 *   and the time of a refusal does not tell whether an account exists; a password longer than
 *   PasswordPolicy::MAX_BYTES is refused before any hashing, whatever the identifier;
 * - a refusal is login_rejected, whatever the reason;
 * - a password that verifies against a hash made with other parameters than the installation's is
 *   hashed again, and the store replaces the hash only while it is still the one verified; when it
 *   is no longer, the password was changed meanwhile and the login is refused.
 *
 * completeLocally() does what complete() does and also gives the hash the account held once the
 * login was through with it (Dto\LocalLogin). stillCurrent() reads the account again and tells
 * whether it still holds that hash: a reset or a change of the password between the check and the
 * session the login issues makes it false (PRD 5.16, a reset ends every session that knew the old
 * password). Argon2id salts every hash, so a password set again to the same text has another hash.
 *
 * The assertion's issuer is the installation's local issuer (cbox-cms.identity.local.issuer, or
 * app.url), its subject the actor's id, its auth_time the Clock's time, its amr `pwd` (RFC 8176),
 * with no acr and no groups. Whether the actor may log in, its state, class and the login policy,
 * is the login path's to ask (CheckLoginPolicy), not the connection's. No identifier or password
 * reaches a message.
 */
#[Internal]
final readonly class LocalConnection implements LoginConnection
{
    /** The amr value of a password (RFC 8176). */
    public const string PASSWORD_METHOD = 'pwd';

    private const int STATE_BYTES = 32;

    public function __construct(
        private LocalCredentialStore $store,
        private PasswordHasher $hasher,
        private Issuer $issuer,
        private Clock $clock,
    ) {}

    #[Override]
    public function id(): ConnectionId
    {
        return new ConnectionId(LoginPolicy::LOCAL_CONNECTION);
    }

    #[Override]
    public function flow(): LoginFlow
    {
        return LoginFlow::Direct;
    }

    #[Override]
    public function start(): LoginStarted
    {
        return new LoginStarted(new PendingLogin($this->id(), LoginFlow::Direct, LoginState::fromRandomBytes(random_bytes(self::STATE_BYTES))));
    }

    #[Override]
    public function complete(PendingLogin $pending, LoginResponse $response): VerifiedAssertion
    {
        return $this->completeLocally($pending, $response)->assertion;
    }

    /**
     * complete(), with the actor and the hash the account held once the login was through with it.
     *
     * @throws LoginRefused when the response is not the pending login's, or the password does not verify
     */
    public function completeLocally(PendingLogin $pending, LoginResponse $response): LocalLogin
    {
        $pending->check($this->id(), $response);

        if (! $response instanceof SubmittedCredentials || strlen($response->secret()) > PasswordPolicy::MAX_BYTES) {
            throw LoginRefused::because(LoginErrorCode::Rejected);
        }

        $login = LoginIdentifier::typed($response->identifier);
        $account = $login instanceof LoginIdentifier ? $this->store->find($login) : null;
        $password = $response->secret() === '' ? null : new Password($response->secret());

        if (! $account instanceof LocalAccount || ! $password instanceof Password) {
            $this->hasher->verify($password ?? new Password(' '), $this->hasher->dummy());

            throw LoginRefused::because(LoginErrorCode::Rejected);
        }

        if (! $this->hasher->verify($password, $account->hash)) {
            throw LoginRefused::because(LoginErrorCode::Rejected);
        }

        $hash = $this->rehash($account, $password);

        if (! $hash instanceof PasswordHash) {
            throw LoginRefused::because(LoginErrorCode::Rejected);
        }

        return new LocalLogin(
            new VerifiedAssertion(
                $this->id(),
                $this->issuer,
                new Subject($account->actor->toString()),
                $this->clock->now(),
                [new AuthenticationMethod(self::PASSWORD_METHOD)],
            ),
            $account->actor,
            $hash,
        );
    }

    /**
     * Whether the account of the login still holds the hash it held once the login was through
     * with it; false when the account is gone or its password was set since.
     */
    public function stillCurrent(LocalLogin $login): bool
    {
        $account = $this->store->ofActor($login->actor);

        return $account instanceof LocalAccount && $account->hash->equals($login->hash);
    }

    /**
     * The assertion of a password reset (PRD 5.16): the account's password was just set with a
     * reset token, which proved that the person holds the account's email and chose the password
     * now. Only a store's resetPassword() gives the account, so this is the connection's word for
     * that login, as complete() is for a typed password: the local issuer, the actor's id, the
     * Clock's time and the amr `pwd`.
     */
    public function resetAssertion(LocalAccount $account): VerifiedAssertion
    {
        return new VerifiedAssertion(
            $this->id(),
            $this->issuer,
            new Subject($account->actor->toString()),
            $this->clock->now(),
            [new AuthenticationMethod(self::PASSWORD_METHOD)],
        );
    }

    /**
     * The installation's local issuer, which every assertion of the connection names.
     */
    public function issuer(): Issuer
    {
        return $this->issuer;
    }

    /**
     * The hash the account holds after the rehash, if one was needed, or null when the store held
     * another hash than the one verified, because the password was set meanwhile.
     */
    private function rehash(LocalAccount $account, Password $password): ?PasswordHash
    {
        if (! $this->hasher->needsRehash($account->hash)) {
            return $account->hash;
        }

        $rehashed = $this->hasher->hash($password);

        return $this->store->rehash($account->actor, $account->hash, $rehashed) ? $rehashed : null;
    }
}
