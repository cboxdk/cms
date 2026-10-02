<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;

/**
 * The lockers of the probe schema of classified properties, a list of objects with a classified
 * property.
 */
final readonly class Lockers
{
    /**
     * @param  list<Locker>  $lockers
     */
    public function __construct(public array $lockers) {}

    public function visibleTo(ClassificationAccess $access): self
    {
        return new self(array_map(static fn (Locker $locker): Locker => $locker->visibleTo($access), $this->lockers));
    }
}
