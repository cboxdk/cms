<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fixtures;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Surface;

/**
 * An action declared for two surfaces, for the attribute tests.
 */
#[Action(surfaces: [Surface::Rest, Surface::Mcp])]
#[Experimental]
final readonly class ReleaseVariantAction {}
