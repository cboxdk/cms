<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A fixture of the protocol tests: an object without members, such as the props of a panel point
 * that has none, bound to a class without a constructor, whose implicit one takes nothing.
 */
#[Experimental]
final readonly class Seal {}
