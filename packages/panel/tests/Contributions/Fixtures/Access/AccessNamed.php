<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Access;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * What the name-only access queries of the contribution tests answer: the query's name, which no
 * test reads, because the actions exist only to register the names.
 */
final readonly class AccessNamed implements Result
{
    public function __construct(public string $query) {}
}
