<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent;

/**
 * A class that does not implement Event, which a subscriber beside it lists as one.
 */
final readonly class PlainValue {}
