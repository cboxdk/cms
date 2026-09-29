<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand;

use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * A query class without #[Query], which the type arguments beside it name.
 */
final readonly class FindsLabel implements Query {}
