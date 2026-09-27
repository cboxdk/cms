<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What the fields inside a group take from the group while a blueprint document is read: whether
 * agents see it, which is their own default for `agents` (PRD 12.2). They inherit its
 * classification too, which the reader does not need to carry, because no classification changes
 * how a field inside a group is read.
 */
#[Internal]
final readonly class EnclosingGroup
{
    /**
     * @param  bool  $agents  whether agents see the group
     */
    public function __construct(
        public bool $agents,
    ) {}

    /**
     * The group to assume for nested fields before the field that holds them has been read: hidden
     * from agents, the narrowest reading.
     */
    public static function unknown(): self
    {
        return new self(false);
    }
}
