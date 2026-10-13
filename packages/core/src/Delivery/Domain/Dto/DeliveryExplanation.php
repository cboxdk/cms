<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use InvalidArgumentException;

/**
 * The body of an answer of the delivery API with debug=1 (PRD 8.9, GUARDRAILS 5),
 * delivery-explanation.v1.json: the answer the request would have had, a record with its meta, a
 * problem, or neither for a redirect (301, whose URL the explanation's redirect step names), with
 * its HTTP status, and the explanation of the resolution, a document of path-explanation.v1.json.
 * The generated codec DeliveryExplanationCodecV1 writes it.
 */
#[Experimental]
final readonly class DeliveryExplanation
{
    /**
     * @throws InvalidArgumentException unless it holds a record with its meta, or a problem, or neither with the status 301
     */
    public function __construct(
        public ?JsonDocument $data,
        public JsonDocument $explanation,
        public ?DeliveryMeta $meta,
        public ?JsonDocument $problem,
        public HttpStatus $status,
    ) {
        $record = $data instanceof JsonDocument && $meta instanceof DeliveryMeta;
        $redirect = $status === HttpStatus::MovedPermanently;

        if (($data instanceof JsonDocument) !== ($meta instanceof DeliveryMeta) || ($record === $problem instanceof JsonDocument) !== $redirect || ($redirect && $record)) {
            throw new InvalidArgumentException('A delivery explanation holds a record with its meta, or a problem, and not both; a redirect, with the status 301, holds neither.');
        }
    }
}
