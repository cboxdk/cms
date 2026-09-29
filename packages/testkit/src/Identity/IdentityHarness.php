<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;

/**
 * What the shared suites ActorDirectoryContract and CredentialVerifierContract run against: a
 * seeder, and the directory and the verifier under test, which read what the seeder wrote.
 */
#[Experimental]
interface IdentityHarness extends IdentitySeeder
{
    public function directory(): ActorDirectory;

    public function verifier(): CredentialVerifier;
}
