<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * The fixture command for the registry tests.
 */
#[Command('fixture.note.create', version: 1)]
final readonly class CreateNote implements CommandInput
{
    public function __construct(
        public string $title,
    ) {}
}
