<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\TransportCredential;

/**
 * Where a service credential is issued (PRD 5.16): the port IssueServiceCredential writes through.
 * It makes the token, 256 random bits and a checksum (ServiceCredentialToken), stores the
 * credential under the SHA-256 of the token, and gives the token back, which is shown once and
 * never stored. The store's rows may be written only by the owner role, so the implementation runs
 * in the maintenance process; the credential verifier reads what it wrote.
 */
#[Internal]
interface ServiceCredentialIssuer
{
    /**
     * @throws CredentialIssueRefused when this process cannot write the store
     */
    public function issue(IssuedCredential $credential): TransportCredential;
}
