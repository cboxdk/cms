<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Clock\FakeClock;

/**
 * What the shared suite LocalCredentialStoreContract needs besides the store:
 *
 * - store() is the store under test, with no account and no token;
 * - clock() is the FakeClock the store reads its time from, which the suite moves;
 * - actor() makes a new actor of the actor register the store binds to, without a local account.
 */
#[Experimental]
interface LocalCredentialStoreHarness
{
    public function store(): LocalCredentialStore;

    public function clock(): FakeClock;

    public function actor(): ActorId;
}
