<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A newer version of the Panel fixture's sections, whose older version has no downcast, for
 * PointDowncastsTest.
 */
#[Experimental]
final readonly class NewerSections {}
