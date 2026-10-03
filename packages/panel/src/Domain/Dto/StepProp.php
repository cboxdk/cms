<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;

/**
 * A flow step's command form, `<name>@<version>`, whether it runs before the submit or after the
 * receipt, the paths of the command document it may change, and how long it may take
 * (contributions.v1.json, `#/$defs/step`).
 */
#[Internal]
final readonly class StepProp
{
    /**
     * @param  list<string>  $patches
     */
    public function __construct(
        public string $command,
        public StepPosition $position,
        public array $patches,
        public int $timeoutSeconds,
    ) {}
}
