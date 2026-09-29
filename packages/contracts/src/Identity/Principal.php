<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Who a command or a read runs as, as CredentialVerifier::verify() gives it (PRD 5.16, 6.2). There
 * are exactly two, and nothing else implements the interface:
 *
 * - ActorPrincipal: an actor, with its on-behalf-of chain, the issuer kind of its credential and
 *   the credential's classification ceiling.
 * - AnonymousPrincipal: no credential, for public reads and public writes such as submissions and
 *   registrations (invariant 25). Its ceiling is public.
 */
#[Experimental]
interface Principal
{
    /**
     * The highest classification the principal's credential may ever read. What a read may see is
     * the AccessContext's classification access, which never exceeds this.
     */
    public function classificationCeiling(): ClassificationAccess;
}
