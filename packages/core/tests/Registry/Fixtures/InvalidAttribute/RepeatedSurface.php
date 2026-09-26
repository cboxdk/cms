<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;

/**
 * An action that lists a surface twice, which the attribute refuses.
 */
#[Action(surfaces: [Surface::Rest, Surface::Rest])]
final readonly class RepeatedSurface {}
