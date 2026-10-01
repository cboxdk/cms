<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fixtures;

use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * A read that needs a permission, which the authorizers' behaviour tests hand over.
 */
final readonly class PrivateProbe implements Query {}
