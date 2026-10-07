<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;

/**
 * One picker's read of the grants page's form (PRD 5.10, 13.4): the result of its query as the
 * query's result codec wrote it at the access the read had, or the rejection, the problem details
 * (problem.v1.json) of a rejected read, never both, as every read a panel page shows (ReadAnswer),
 * and neither for a read the pipeline could not make, which the page shows as the picker's
 * options being unavailable.
 */
#[Internal]
final readonly class PickerRead
{
    public function __construct(
        public ?JsonDocument $result,
        public ?JsonDocument $rejection,
    ) {}
}
