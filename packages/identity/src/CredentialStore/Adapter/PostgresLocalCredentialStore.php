<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\CredentialStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalAccountExists;
use Cbox\Cms\Contracts\Identity\LocalAccountMissing;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Identity\PasswordResetRefused;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use LogicException;
use Override;

/**
 * The local accounts and their reset tokens in the credential store, the schema cms_identity, on
 * the identity role's connection, cbox-cms.identity.connection (PRD 5.16, "Lokale konti"). The app
 * role cannot read the tables, so no other connection is used.
 *
 * Every statement runs on its own, but resetPassword(), which marks the token used, sets the hash
 * and marks the actor's other unused tokens used in one transaction of the identity connection. bind() inserts with ON CONFLICT DO NOTHING
 * and tells from the account of the actor whether the actor or the login was taken; a foreign key
 * violation, SQLSTATE 23503, is an actor the register does not have. A rehash and a password
 * change are one UPDATE each, the rehash only while the row has the hash the caller verified. A
 * reset token is stored as its SHA-256 and taken with one UPDATE that requires it unused and not
 * expired at the Clock's time, so two resets with one token set one password. A prune is one
 * DELETE of the tokens whose use, or else expiry, is before the time given: a used token has its
 * used_at at or before its expires_at, so it goes once it was used that long ago. Times are the
 * Clock's, written with their microseconds.
 */
#[Internal]
final readonly class PostgresLocalCredentialStore implements LocalCredentialStore
{
    public const string ACCOUNTS = 'local_accounts';

    public const string TOKENS = 'password_reset_tokens';

    private const string TIME = 'Y-m-d H:i:s.uP';

    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string TAKE_TOKEN = 'update cms_identity.password_reset_tokens set used_at = ? '
        .'where token_hash = ? and used_at is null and expires_at > ? returning actor_id';

    /** Takes every other token of the actor that is still unused, at the time of the reset. */
    private const string TAKE_OTHERS = 'update cms_identity.password_reset_tokens set used_at = ? '
        .'where actor_id = ? and used_at is null and expires_at > ? and created_at <= ? and token_hash <> ?';

    private const string PRUNE = 'delete from cms_identity.password_reset_tokens where coalesce(used_at, expires_at) < ?';

    private const string CHANGE = 'update cms_identity.local_accounts '
        .'set password_hash = ?, password_changed_at = ?, version = version + 1 '
        .'where actor_id = ? returning actor_id, login, password_hash, version, password_changed_at, created_at';

    public function __construct(
        private DatabaseManager $database,
        private ?string $connection,
        private Clock $clock,
    ) {}

    #[Override]
    public function bind(ActorId $actor, LoginIdentifier $login, PasswordHash $hash): LocalAccount
    {
        $now = $this->clock->now();

        try {
            $inserted = $this->connection()->table(CredentialStore::table(self::ACCOUNTS))->insertOrIgnore([
                'actor_id' => $actor->toString(),
                'login' => $login->value,
                'password_hash' => $hash->value,
                'password_changed_at' => $now->format(self::TIME),
                'version' => LocalAccount::FIRST_VERSION,
                'created_at' => $now->format(self::TIME),
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::FOREIGN_KEY_VIOLATION) {
                throw InvalidIdentity::unknownActor($actor);
            }

            throw $exception;
        }

        if ($inserted === 0) {
            throw $this->ofActor($actor) instanceof LocalAccount ? LocalAccountExists::forActor($actor) : LocalAccountExists::forLogin($actor);
        }

        return new LocalAccount($actor, $login, $hash, LocalAccount::FIRST_VERSION, $now, $now);
    }

    #[Override]
    public function find(LoginIdentifier $login): ?LocalAccount
    {
        $row = $this->accounts()->where('login', $login->value)->first();

        return $row === null ? null : $this->account($row);
    }

    #[Override]
    public function ofActor(ActorId $actor): ?LocalAccount
    {
        $row = $this->accounts()->where('actor_id', $actor->toString())->first();

        return $row === null ? null : $this->account($row);
    }

    #[Override]
    public function rehash(ActorId $actor, PasswordHash $verified, PasswordHash $rehashed): bool
    {
        return $this->connection()->table(CredentialStore::table(self::ACCOUNTS))
            ->where('actor_id', $actor->toString())
            ->where('password_hash', $verified->value)
            ->update([
                'password_hash' => $rehashed->value,
                'version' => $this->connection()->raw('version + 1'),
            ]) === 1;
    }

    #[Override]
    public function changePassword(ActorId $actor, PasswordHash $hash): LocalAccount
    {
        $row = $this->connection()->selectOne(self::CHANGE, [$hash->value, $this->clock->now()->format(self::TIME), $actor->toString()]);

        return $row === null ? throw LocalAccountMissing::of($actor) : $this->account($row);
    }

    #[Override]
    public function issueResetToken(ActorId $actor, DateTimeImmutable $expiresAt): PasswordResetToken
    {
        $now = $this->clock->now();

        if ($expiresAt <= $now) {
            throw InvalidIdentity::expiry($expiresAt, $now);
        }

        $token = PasswordResetToken::fromSecret(random_bytes(PasswordResetToken::SECRET_BYTES));

        try {
            $this->connection()->table(CredentialStore::table(self::TOKENS))->insert([
                'token_hash' => $token->hash(),
                'actor_id' => $actor->toString(),
                'expires_at' => $expiresAt->format(self::TIME),
                'created_at' => $now->format(self::TIME),
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::FOREIGN_KEY_VIOLATION) {
                throw LocalAccountMissing::of($actor);
            }

            throw $exception;
        }

        return $token;
    }

    #[Override]
    public function resetTokenActor(PasswordResetToken $token): ?ActorId
    {
        $actor = $this->connection()->table(CredentialStore::table(self::TOKENS))
            ->where('token_hash', $token->hash())
            ->whereNull('used_at')
            ->where('expires_at', '>', $this->clock->now()->format(self::TIME))
            ->value('actor_id');

        return is_string($actor) ? ActorId::fromString($actor) : null;
    }

    #[Override]
    public function resetPassword(PasswordResetToken $token, PasswordHash $hash): LocalAccount
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $token, $hash): LocalAccount {
            $now = $this->clock->now()->format(self::TIME);
            $taken = $connection->selectOne(self::TAKE_TOKEN, [$now, $token->hash(), $now]);
            $actor = is_object($taken) && property_exists($taken, 'actor_id') && is_string($taken->actor_id) ? $taken->actor_id : null;

            if ($actor === null) {
                throw PasswordResetRefused::token();
            }

            $row = $connection->selectOne(self::CHANGE, [$hash->value, $now, $actor]);

            if ($row === null) {
                throw PasswordResetRefused::token();
            }

            $connection->update(self::TAKE_OTHERS, [$now, $actor, $now, $now, $token->hash()]);

            return $this->account($row);
        });
    }

    #[Override]
    public function pruneResetTokens(DateTimeImmutable $before): int
    {
        return $this->connection()->delete(self::PRUNE, [$before->format(self::TIME)]);
    }

    private function accounts(): Builder
    {
        return $this->connection()->table(CredentialStore::table(self::ACCOUNTS))
            ->select(['actor_id', 'login', 'password_hash', 'version', 'password_changed_at', 'created_at']);
    }

    private function account(mixed $row): LocalAccount
    {
        $value = (static fn (string $column): mixed => is_object($row) && property_exists($row, $column) ? $row->{$column} : null);
        $text = static function (string $column) use ($value): string {
            $read = $value($column);

            return is_string($read) ? $read : throw new LogicException(sprintf('The local account row has no text in %s.', $column));
        };
        $version = $value('version');

        return new LocalAccount(
            ActorId::fromString($text('actor_id')),
            new LoginIdentifier($text('login')),
            new PasswordHash($text('password_hash')),
            is_int($version) ? $version : (int) (is_string($version) ? $version : 0),
            new DateTimeImmutable($text('password_changed_at')),
            new DateTimeImmutable($text('created_at')),
        );
    }

    private function connection(): Connection
    {
        if ($this->connection === null) {
            throw new LogicException('cbox-cms.identity.connection names no database connection, so the local accounts cannot be read or written; cms:doctor reports it as identity.connection.');
        }

        return $this->database->connection($this->connection);
    }
}
