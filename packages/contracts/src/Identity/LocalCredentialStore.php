<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;

/**
 * The store of local credentials (PRD 5.16, "Identitetskontrakten" and "Lokale konti"): the local
 * accounts, each bound to an actor of the actor register, so there is one list of people, and the
 * tokens that reset their passwords. It stores hashes only: it never sees a password, and keeps a
 * reset token only as its SHA-256.
 *
 * - bind() binds a new local account to an actor that exists, with its login identifier and the
 *   hash of its first password, at version 1, made and set at the Clock's time. A registration
 *   calls it between actor.register, which makes the actor pending, and actor.activate.
 * - find() gives the account of a login identifier, ofActor() the account of an actor, or null.
 *   They are how a login verifies a password: the caller verifies the password against the
 *   account's hash, and against a fixed dummy hash when there is no account, so an unknown login
 *   costs what a wrong password costs.
 * - rehash() replaces the hash of a login whose hash was made with other parameters, only while the
 *   account still has the hash the caller verified; it keeps when the password was set.
 * - changePassword() sets a new hash, at the Clock's time.
 * - issueResetToken() makes a reset token for an account, which expires at the time given;
 *   resetPassword() takes a token once, before it expires, and sets the new hash with it. The
 *   reset also takes every other token of the account that is still unused, so a link mailed
 *   earlier cannot set the password again after it. resetTokenActor() looks a token up without
 *   taking it, so a reset page can refuse a dead link before it checks and hashes a password.
 * - pruneResetTokens() removes the tokens that were used, or expired, before a time, which the
 *   maintenance process passes as 24 hours ago (cms:identity:prune).
 *
 * Every change of the hash adds 1 to the account's version. A message of the store never holds a
 * login identifier, a hash or a token. The default is the identity module's
 * PostgresLocalCredentialStore, in the schema cms_identity on the identity role's connection, which
 * the app role cannot read.
 */
#[Experimental]
interface LocalCredentialStore
{
    /**
     * @throws LocalAccountExists when the login identifier is another account's, or the actor has an account
     * @throws InvalidIdentity when no actor has the id
     */
    public function bind(ActorId $actor, LoginIdentifier $login, PasswordHash $hash): LocalAccount;

    public function find(LoginIdentifier $login): ?LocalAccount;

    public function ofActor(ActorId $actor): ?LocalAccount;

    /**
     * Whether the hash was replaced: false, and nothing changed, when the actor has no account or
     * its hash is no longer $verified.
     */
    public function rehash(ActorId $actor, PasswordHash $verified, PasswordHash $rehashed): bool;

    /**
     * @throws LocalAccountMissing when the actor has no local account
     */
    public function changePassword(ActorId $actor, PasswordHash $hash): LocalAccount;

    /**
     * @throws LocalAccountMissing when the actor has no local account
     * @throws InvalidIdentity when the expiry is not after the Clock's time
     */
    public function issueResetToken(ActorId $actor, DateTimeImmutable $expiresAt): PasswordResetToken;

    /**
     * The actor of a reset token that is usable at the Clock's time, unused and not expired, or
     * null for a token that is unknown, used or expired. It takes nothing.
     */
    public function resetTokenActor(PasswordResetToken $token): ?ActorId;

    /**
     * Marks the token used and sets the new hash, both or neither.
     *
     * @throws PasswordResetRefused when the token is unknown, used or expired
     */
    public function resetPassword(PasswordResetToken $token, PasswordHash $hash): LocalAccount;

    /**
     * Removes every reset token that was used before $before, and every one that expired before
     * it, used or not, and returns how many it removed. A token that is still usable, or that was
     * used or expired at or after $before, stays.
     */
    public function pruneResetTokens(DateTimeImmutable $before): int;
}
