<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\AbstractAction;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;

/**
 * #[Action] on an abstract class, which has no instance to call.
 */
#[Action(surfaces: [Surface::Rest])]
abstract class BaseAction {}
