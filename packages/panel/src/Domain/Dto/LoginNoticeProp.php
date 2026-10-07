<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Tone;

/**
 * One notice of the login page (PRD 13.4), `#/$defs/notice` of login.v1.json: the addon it comes
 * from, the contribution's id, the translation key of its message in the addon's catalogue and
 * its tone. Written by the generated LoginPageCodecV1.
 */
#[Internal]
final readonly class LoginNoticeProp
{
    public function __construct(
        public AddonNamespace $addon,
        public ContributionId $id,
        public string $message,
        public Tone $tone,
    ) {}
}
