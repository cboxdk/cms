<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The fixture query for the registry tests.
 */
#[QueryType('fixture.note.find', version: 1)]
final readonly class FindNote implements Query
{
    public function __construct(
        public string $title,
    ) {}
}
