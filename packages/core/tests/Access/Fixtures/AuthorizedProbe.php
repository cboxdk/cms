<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fixtures;

use Cbox\Cms\Contracts\Pipeline\Command;

/**
 * A command the authorizers' behaviour tests hand over; the authorizers decide by its name and its
 * aggregates, never by its fields.
 */
final readonly class AuthorizedProbe implements Command {}
