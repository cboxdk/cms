<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * A command of the host, exposed on Inertia, whose form the addon's checks and steps extend.
 */
#[Command('notes.draft', version: 1)]
final readonly class DraftNote implements CommandInput
{
    public function __construct(public string $note) {}
}
