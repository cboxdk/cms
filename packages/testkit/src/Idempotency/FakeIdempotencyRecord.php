<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use DateTimeImmutable;

/**
 * A completed claim in the FakeIdempotencyStore: the content hash and the changeset it committed.
 */
#[Internal]
final readonly class FakeIdempotencyRecord
{
    public function __construct(
        public ContentHash $hash,
        public ChangesetId $changesetId,
    ) {}

    /**
     * Whether the record is live at $now: up to and including the Standard receipt's expiry for
     * its changeset.
     */
    public function isLiveAt(DateTimeImmutable $now): bool
    {
        $expiresAt = RetentionClass::Standard->expiresAt($this->changesetId);

        return $expiresAt instanceof DateTimeImmutable && $now <= $expiresAt;
    }
}
