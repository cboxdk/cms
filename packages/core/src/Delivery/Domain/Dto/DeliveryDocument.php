<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;

/**
 * The body of a 200 answer of the delivery API (PRD 8.8, 8.9), delivery.v1.json: the record of what
 * the path shows, as its type's generated codec wrote it, and what it is. The generated codec
 * DeliveryCodecV1 writes it.
 */
#[Experimental]
final readonly class DeliveryDocument
{
    public function __construct(
        public JsonDocument $data,
        public DeliveryMeta $meta,
    ) {}
}
