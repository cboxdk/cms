<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NeitherFinalNorReadonly;

use Cbox\Cms\Contracts\Attributes\Command;

/**
 * #[Command] on a class that is neither final nor readonly. The build names both in one problem.
 */
#[Command('fixture.plain.run', version: 1)]
class PlainCommand
{
    public string $title = '';
}
