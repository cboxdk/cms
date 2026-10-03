<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The installation's brand as every page of the panel shares it (PRD 13.4), the prop brand: the
 * product name, the logo of the shell's header and the brand image of the login page, each with
 * the addresses the panel serves its light and dark version at. Its JSON form is brand.v1.json in
 * packages/panel/resources/schemas/pages, written only by the generated PanelBrandCodecV1
 * (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class PanelBrand
{
    public function __construct(
        public ?PanelBrandLogo $login,
        public ?PanelBrandLogo $logo,
        public string $name,
    ) {}
}
