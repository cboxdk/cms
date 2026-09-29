<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One aggregate a write read and the version it read it at (PRD 6.2 phase 1). A null version
 * says the aggregate did not exist when it was read, as for the entry a create command makes.
 * At commit the kernel checks that each aggregate is still at its read version, or still absent,
 * and rejects the command with a version conflict otherwise.
 */
#[Experimental]
final readonly class ReadVersion
{
    public function __construct(
        public AggregateRef $aggregate,
        public ?AggregateVersion $version,
    ) {}

    public static function absent(AggregateRef $aggregate): self
    {
        return new self($aggregate, null);
    }

    public static function at(AggregateRef $aggregate, AggregateVersion $version): self
    {
        return new self($aggregate, $version);
    }

    public function existed(): bool
    {
        return $this->version instanceof AggregateVersion;
    }
}
