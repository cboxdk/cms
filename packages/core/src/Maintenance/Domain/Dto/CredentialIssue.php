<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;

/**
 * A service credential to issue (PRD 5.16): the active service actor it belongs to, its
 * classification ceiling and when it expires. Every credential has an expiry.
 */
#[Internal]
final readonly class CredentialIssue
{
    public function __construct(
        public ActorId $actor,
        public ClassificationAccess $ceiling,
        public DateTimeImmutable $expiresAt,
    ) {}
}
