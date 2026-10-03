<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The props of the page for an address below the panel that it has no page for (PRD 13.4),
 * Errors/NotFound in js/panel: the address of the panel's start. Its JSON form is not-found.v1.json
 * in packages/panel/resources/schemas/pages, written only by the generated NotFoundPageCodecV1
 * (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class NotFoundPage
{
    public function __construct(
        public string $home,
    ) {}
}
