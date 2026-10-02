<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\Password;

/**
 * What the shared suite BreachedPasswordsContract needs: the implementation under test, a way to
 * make a password known from breaches where that implementation looks, and a way to make the
 * service it asks stop answering. A harness for an implementation that asks a service fakes that
 * service's transport; it never asks the real one.
 */
#[Experimental]
interface BreachedPasswordsHarness
{
    public function breachedPasswords(): BreachedPasswords;

    /**
     * Makes the password known from breaches.
     */
    public function breach(Password $password): void;

    /**
     * Makes every later check fail, as a service that does not answer would.
     */
    public function goDown(): void;
}
