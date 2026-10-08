<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Tone;

/**
 * One notice of the login page (PRD 13.4), `#/$defs/notice` of login.v1.json: the addon it comes
 * from, the contribution's id, its message in the page's locale, as ResolveLoginNotices read it
 * from the addon's compiled catalogue, and its tone. A credential page carries no catalogue, so
 * the text travels and not its key; a key the addon ships no text for travels as the key. Written
 * by the generated LoginPageCodecV1.
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
