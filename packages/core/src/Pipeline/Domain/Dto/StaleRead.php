<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * An aggregate whose version changed after it was read: the version the call read, or null when
 * it was absent, and the version it has now, or null when it is absent now.
 */
#[Internal]
final readonly class StaleRead
{
    public function __construct(
        public AggregateRef $aggregate,
        public ?AggregateVersion $read,
        public ?AggregateVersion $current,
    ) {}
}
