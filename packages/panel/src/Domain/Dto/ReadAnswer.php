<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;

/**
 * The answer of a read a panel page shows (PRD 13.4): the result as the query's result codec wrote
 * it at the access the read had, or the rejection, the problem details of a rejected read, one of
 * the two and never both, as the Inertia profile answers a read (InertiaQueryOutcome).
 */
#[Internal]
final readonly class ReadAnswer
{
    public function __construct(
        public ?JsonDocument $result,
        public ?JsonDocument $rejection,
    ) {}
}
