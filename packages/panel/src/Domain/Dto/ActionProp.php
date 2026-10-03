<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\Tone;

/**
 * What an action renders and runs (contributions.v1.json, `#/$defs/action`): the command it runs,
 * `<name>@<version>`, the translation key of its text and its icon, the command document's
 * properties it fills from the point's props, how it asks before it runs and its tone.
 */
#[Internal]
final readonly class ActionProp
{
    /**
     * @param  list<PrefillProp>  $prefill
     */
    public function __construct(
        public string $command,
        public string $label,
        public ?string $icon,
        public array $prefill,
        public Confirm $confirm,
        public Tone $tone,
    ) {}
}
