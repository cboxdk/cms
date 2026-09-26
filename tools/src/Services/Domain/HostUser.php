<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Services\Domain;

use InvalidArgumentException;

/**
 * The user and group the php container runs as, the host user's, so files it writes into the
 * bind mount belong to the developer (compose.yaml, `user: "${CMS_UID}:${CMS_GID}"`).
 */
final readonly class HostUser
{
    public function __construct(
        public int $uid,
        public int $gid,
    ) {
        if ($uid < 0 || $gid < 0) {
            throw new InvalidArgumentException("A user id and a group id are not negative, not {$uid} and {$gid}.");
        }
    }
}
