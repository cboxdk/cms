<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * The addon's command, exposed on Inertia, which its actions issue.
 */
#[Command('approvals.request', version: 1)]
final readonly class RequestApproval implements CommandInput
{
    public function __construct(public string $note) {}
}
