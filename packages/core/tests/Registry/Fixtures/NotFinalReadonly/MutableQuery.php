<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * #[Query] on a final class that is not readonly.
 */
#[QueryType('fixture.mutable.find', version: 1)]
final class MutableQuery implements Query
{
    public function __construct(
        public string $title,
    ) {}
}
