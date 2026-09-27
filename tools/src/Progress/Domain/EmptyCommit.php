<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

/**
 * A commit that changes no file, by its full hash and the first line of its message.
 */
final readonly class EmptyCommit
{
    public function __construct(
        public string $commit,
        public string $subject,
    ) {}
}
