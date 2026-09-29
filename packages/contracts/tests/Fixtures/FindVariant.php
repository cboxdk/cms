<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fixtures;

use Cbox\Cms\Contracts\Attributes\Query;

/**
 * A query DTO for the attribute tests.
 */
#[Query('entry.find_variant', version: 3)]
final readonly class FindVariant {}
