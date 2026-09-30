<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;

/**
 * The documents of the delivery API (PRD 8.8, 8.9, 8.12), canonical JSON with sorted keys and no
 * whitespace:
 *
 * - body() writes an answer: a record as `{"data":<record>,"meta":{...}}`, the record exactly as its
 *   codec wrote it and the meta with the canonical URL, the contract version, the locale and the
 *   type; a problem as the problem details document; and an explanation as `{"data":...,
 *   "explanation":{...},"meta":...,"problem":...,"status":...}`, with every step of the resolution.
 * - fragment() writes what a fragment holds of an answer, and stored() reads it back, or gives null
 *   for bytes it did not write, so a damaged fragment is rebuilt instead of served.
 */
#[Internal]
interface DeliveryDocuments
{
    public function body(DeliveryAnswer $answer): string;

    public function fragment(StoredAnswer $answer): string;

    public function stored(string $fragment): ?StoredAnswer;
}
