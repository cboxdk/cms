<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The algorithm a publisher signs an addon's panel bundle with (PRD 13.8, decision D8 of the panel
 * extension architecture): Ed25519 (RFC 8032), the one the kernel verifies, through libsodium.
 */
#[Experimental]
enum SignatureAlgorithm: string
{
    case Ed25519 = 'ed25519';
}
