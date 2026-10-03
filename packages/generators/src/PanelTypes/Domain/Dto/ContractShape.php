<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;

/**
 * The JSON document of a command or of a query's result an addon's UI exchanges, by the command's
 * or query's name and version: the document a contribution issues or a form check reads, or the
 * result a data query gives a contribution.
 */
#[Internal]
final readonly class ContractShape
{
    public function __construct(
        public CommandRef $ref,
        public ShapeDocument $document,
    ) {}
}
