<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use RuntimeException;

/**
 * A surface answered with something its profile cannot read as a receipt or a problem.
 */
final class SurfaceAnswerUnreadable extends RuntimeException {}
