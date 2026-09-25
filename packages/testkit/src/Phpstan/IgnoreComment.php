<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * One phpstan-ignore annotation of any form in a comment token: the tag as written, the line it is on
 * and the namespace the comment is in ('' before the first namespace or without one).
 */
#[Internal]
final readonly class IgnoreComment
{
    public function __construct(
        public string $tag,
        public int $line,
        public string $namespace,
    ) {}
}
