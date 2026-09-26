<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Elsewhere;

/**
 * Declared in a namespace that does not match its path, so the PSR-4 autoloader cannot find it.
 */
final readonly class Misplaced {}
