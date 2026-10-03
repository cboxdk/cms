<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The props of the start page of a person who signed in (PRD 13.4), Home in js/panel: the address
 * the logout posts to. Its JSON form is home.v1.json in packages/panel/resources/schemas/pages,
 * written only by the generated HomePageCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class HomePage
{
    public function __construct(
        public string $logout,
    ) {}
}
