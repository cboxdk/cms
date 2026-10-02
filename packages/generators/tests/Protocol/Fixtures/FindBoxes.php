<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The probe query of the query schemas: probe.find_boxes, the boxes with a label.
 */
#[QueryType('probe.find_boxes', version: 1)]
final readonly class FindBoxes implements Query
{
    public function __construct(public string $label) {}
}
