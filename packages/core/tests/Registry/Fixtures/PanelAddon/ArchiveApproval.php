<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * A command of the addon exposed on no surface, so the panel cannot issue it.
 */
#[Command('approvals.archive', version: 1)]
final readonly class ArchiveApproval implements CommandInput
{
    public function __construct(public string $note) {}
}
