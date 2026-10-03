<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\LocalAccounts;

use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Identity\LocalAccounts\Adapter\Argon2idPasswordHasher;
use Cbox\Cms\Identity\LocalAccounts\Domain\Dto\Argon2idParameters;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Closure;
use Override;

/**
 * The real Argon2id hasher, at the parameters a test gives (cheap ones by default, so a test does
 * not spend its time hashing), that records the hash of every verify() and counts every hash(). It
 * is not a fake: it hashes and verifies as the module's hasher does.
 */
final class CountingPasswordHasher implements PasswordHasher
{
    /** @var list<PasswordHash> the hash of each verify(), in order */
    public array $verified = [];

    public int $hashed = 0;

    private readonly Argon2idPasswordHasher $hasher;

    /** @var (Closure(): void)|null */
    private ?Closure $afterVerify = null;

    public function __construct(Argon2idParameters $parameters = new Argon2idParameters(Argon2idParameters::MIN_MEMORY_KIB, 1))
    {
        $this->hasher = new Argon2idPasswordHasher($parameters);
    }

    /**
     * The hash of the last verify(), or null when nothing was verified since forget().
     */
    public function lastVerified(): ?PasswordHash
    {
        return $this->verified === [] ? null : array_last($this->verified);
    }

    /**
     * Runs $then once, right after the next verify() has verified.
     *
     * @param  Closure(): void  $then
     */
    public function whenVerified(Closure $then): void
    {
        $this->afterVerify = $then;
    }

    /**
     * Forgets the verifications so far.
     */
    public function forget(): void
    {
        $this->verified = [];
    }

    #[Override]
    public function hash(Password $password): PasswordHash
    {
        $this->hashed++;

        return $this->hasher->hash($password);
    }

    #[Override]
    public function verify(Password $password, PasswordHash $hash): bool
    {
        $this->verified[] = $hash;
        $verified = $this->hasher->verify($password, $hash);
        $then = $this->afterVerify;
        $this->afterVerify = null;

        if ($then instanceof Closure) {
            $then();
        }

        return $verified;
    }

    #[Override]
    public function needsRehash(PasswordHash $hash): bool
    {
        return $this->hasher->needsRehash($hash);
    }

    #[Override]
    public function dummy(): PasswordHash
    {
        return $this->hasher->dummy();
    }
}
