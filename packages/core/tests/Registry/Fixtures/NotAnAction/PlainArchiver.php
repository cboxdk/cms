<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotAnAction;

use Cbox\Cms\Contracts\Attributes\Action;

/**
 * #[Action] on a class that implements neither WriteAction nor QueryAction.
 */
#[Action(handles: ArchiveNote::class)]
final readonly class PlainArchiver {}
