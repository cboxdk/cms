<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * #[Command] on a final class that is not readonly, so its fields could change after the pipeline
 * has authorized and validated it (GUARDRAILS 2.1).
 */
#[Command('fixture.mutable.create', version: 1)]
final class MutableCommand implements CommandInput
{
    public function __construct(
        public string $title,
    ) {}
}
