<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fixtures;

use Cbox\Cms\Contracts\Pipeline\PublicQuery;

/**
 * A read of published content the authorizers' behaviour tests hand over.
 */
final readonly class PublicProbe implements PublicQuery {}
