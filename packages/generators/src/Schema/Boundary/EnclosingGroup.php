<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Classification;

/**
 * What the fields inside a group take from the group while a blueprint document is read: its
 * classification, and whether agents see it, which is their own default for `agents` (PRD 12.2).
 */
#[Internal]
final readonly class EnclosingGroup
{
    /**
     * @param  ?Classification  $classification  the group's classification, or its group's; null when it could not be read
     * @param  bool  $agents  whether agents see the group
     */
    public function __construct(
        public ?Classification $classification,
        public bool $agents,
    ) {}

    /**
     * The group to assume for nested fields before the field that holds them has been read:
     * without a known classification, and hidden from agents, the narrowest reading.
     */
    public static function unknown(): self
    {
        return new self(null, false);
    }
}
