<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Command;

/**
 * The fixture command for the registry tests.
 */
#[Command('fixture.note.create', version: 1)]
final readonly class CreateNote
{
    public function __construct(
        public string $title,
    ) {}
}
