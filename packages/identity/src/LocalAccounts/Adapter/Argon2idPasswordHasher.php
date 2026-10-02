<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Identity\LocalAccounts\Domain\Dto\Argon2idParameters;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Override;

/**
 * PHP's password_hash() and password_verify() with PASSWORD_ARGON2ID at the installation's
 * parameters (PRD 5.16). The dummy hash is written from the parameters with a fixed salt and a
 * fixed digest of the length PHP writes, so it costs nothing to make and verifying a password
 * against it costs what verifying against a real hash of the same parameters costs; no password
 * verifies against it, short of a collision of Argon2id.
 */
#[Internal]
final readonly class Argon2idPasswordHasher implements PasswordHasher
{
    /** The salt of the dummy hash: 16 bytes, PHP's salt length, in unpadded base64. */
    private const string DUMMY_SALT = 'Y21zLWR1bW15LXNhbHQtMQ';

    /** The digest of the dummy hash: 32 bytes, PHP's digest length, in unpadded base64. */
    private const string DUMMY_DIGEST = 'Y21zLWR1bW15LWRpZ2VzdC1uby1wYXNzd29yZC0wMDE';

    public function __construct(private Argon2idParameters $parameters = new Argon2idParameters) {}

    #[Override]
    public function hash(Password $password): PasswordHash
    {
        return new PasswordHash(password_hash($password->reveal(), PASSWORD_ARGON2ID, $this->options()));
    }

    #[Override]
    public function verify(Password $password, PasswordHash $hash): bool
    {
        return password_verify($password->reveal(), $hash->value);
    }

    #[Override]
    public function needsRehash(PasswordHash $hash): bool
    {
        return password_needs_rehash($hash->value, PASSWORD_ARGON2ID, $this->options());
    }

    #[Override]
    public function dummy(): PasswordHash
    {
        return new PasswordHash(sprintf(
            '$argon2id$v=19$m=%d,t=%d,p=%d$%s$%s',
            $this->parameters->memoryKib,
            $this->parameters->time,
            Argon2idParameters::THREADS,
            self::DUMMY_SALT,
            self::DUMMY_DIGEST,
        ));
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        return [
            'memory_cost' => $this->parameters->memoryKib,
            'time_cost' => $this->parameters->time,
            'threads' => Argon2idParameters::THREADS,
        ];
    }
}
