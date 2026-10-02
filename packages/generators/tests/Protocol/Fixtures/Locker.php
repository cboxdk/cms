<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;

/**
 * A locker of the probe schema of classified properties: its name, and its code, which is
 * personal and Omitted for a reader below personal.
 */
final readonly class Locker
{
    public function __construct(
        public string $name,
        public string|Omitted $code,
    ) {}

    public function visibleTo(ClassificationAccess $access): self
    {
        return new self($this->name, $access->allows(ClassificationAccess::Personal) ? $this->code : Omitted::Field);
    }
}
