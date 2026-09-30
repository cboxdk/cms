<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Rest\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use RuntimeException;

/**
 * A REST request that RestRequest could not read, so nothing ran: a missing or malformed
 * Idempotency-Key, an envelope header that breaks its rule, or a query document its codec refused.
 * It carries the catalog error RestResponse answers with.
 */
#[Internal]
final class RestInputRefused extends RuntimeException
{
    private function __construct(public readonly CatalogError $error, ?DecodingFailed $previous = null)
    {
        parent::__construct($error->message, 0, $previous);
    }

    /**
     * A header of the envelope that is missing or breaks its rule; the message names the header.
     */
    public static function header(ErrorCode $code, string $message, ?DecodingFailed $previous = null): self
    {
        return new self(new CatalogError($code, null, $message), $previous);
    }

    /**
     * A document its codec refused, with the error at the path given.
     */
    public static function document(DecodingFailed $failed, ?FieldPath $path): self
    {
        return new self(new CatalogError($failed->errorCode, $path, $failed->reason), $failed);
    }
}
