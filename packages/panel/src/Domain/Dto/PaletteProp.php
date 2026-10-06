<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;

/**
 * The prop `palette` every page behind the login shares (GUARDRAILS 8, PRD 13.4), from which the
 * command palette is built: the read of action.list as the person who signed in, its result, the
 * document of action.list.result.v1.json the query's result codec wrote, or the rejection, the
 * problem details (problem.v1.json) of a rejected read, never both, and neither for a read the
 * pipeline could not make, which the palette shows as its entries being unavailable. Its JSON
 * form is palette.v1.json in packages/panel/resources/schemas/pages, written only by the generated
 * PalettePropCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class PaletteProp
{
    public function __construct(
        public ?JsonDocument $result,
        public ?JsonDocument $rejection,
    ) {}
}
