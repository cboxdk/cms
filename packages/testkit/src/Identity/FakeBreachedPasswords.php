<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\Password;
use Override;

/**
 * The fake of BreachedPasswords and its own harness: a password is breached when breach() named it,
 * and every check throws BreachedPasswordsUnavailable after goDown(), until comeBack(). It keeps a
 * SHA-256 of each breached password, never the password, so a dump of the fake shows none.
 * checks() counts the checks it answered or refused.
 */
#[Experimental]
final class FakeBreachedPasswords implements BreachedPasswords, BreachedPasswordsHarness
{
    /** @var array<string, true> */
    private array $breached = [];

    private bool $down = false;

    private int $checks = 0;

    public function __construct(Password ...$breached)
    {
        foreach ($breached as $password) {
            $this->breach($password);
        }
    }

    #[Override]
    public function isBreached(Password $password): bool
    {
        $this->checks++;

        if ($this->down) {
            throw BreachedPasswordsUnavailable::because('the fake is down.');
        }

        return isset($this->breached[$this->key($password)]);
    }

    #[Override]
    public function breachedPasswords(): BreachedPasswords
    {
        return $this;
    }

    #[Override]
    public function breach(Password $password): void
    {
        $this->breached[$this->key($password)] = true;
    }

    #[Override]
    public function goDown(): void
    {
        $this->down = true;
    }

    public function comeBack(): void
    {
        $this->down = false;
    }

    public function checks(): int
    {
        return $this->checks;
    }

    private function key(Password $password): string
    {
        return hash('sha256', $password->reveal());
    }
}
