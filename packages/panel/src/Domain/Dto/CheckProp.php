<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\Severity;

/**
 * A form check's command form, `<name>@<version>`, and the most its issues weigh
 * (contributions.v1.json, `#/$defs/check`).
 */
#[Internal]
final readonly class CheckProp
{
    public function __construct(
        public string $command,
        public Severity $severity,
    ) {}
}
