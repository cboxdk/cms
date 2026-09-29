<?php

declare(strict_types=1);

namespace Examples\Contract\Identity;

use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\TransportCredential;

/**
 * A verifier that counts the refusals of the verifier it decorates by reason, for a metric per
 * reason (PRD 5.16: refusals without a known actor are counted, not logged). It passes every call
 * through and changes no result.
 */
final class RejectionCountingVerifier implements CredentialVerifier
{
    /** @var array<string, int> by reason */
    private array $refusals = [];

    public function __construct(private readonly CredentialVerifier $verifier) {}

    public function verify(?TransportCredential $credential): Principal
    {
        try {
            return $this->verifier->verify($credential);
        } catch (CredentialRejected $rejected) {
            $this->refusals[$rejected->reason->value] = $this->refusals($rejected->reason) + 1;

            throw $rejected;
        }
    }

    public function refusals(CredentialErrorCode $reason): int
    {
        return $this->refusals[$reason->value] ?? 0;
    }
}
