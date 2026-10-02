<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * A query of the shape of probe.find_boxes without #[Query], which a query's schema cannot be bound
 * to.
 */
final readonly class UnnamedFind implements Query
{
    public function __construct(public string $label) {}
}
