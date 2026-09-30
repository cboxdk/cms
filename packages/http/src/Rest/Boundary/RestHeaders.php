<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Rest\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Illuminate\Http\Request;
use stdClass;

/**
 * The envelope fields a REST write sends in its headers (PRD 6.1), as the document of
 * envelope.v1.json that the envelope's generated codec reads: Idempotency-Key as idempotency_key,
 * which REST requires, Cbox-Wait-Level as wait_level, Cbox-Dry-Run (true or false) as dry_run and
 * Cbox-Correlation-Id as correlation_id, each left out of the document when the request has no
 * such header, so the codec gives it its default. The rules of each value are the codec's.
 */
#[Internal]
final readonly class RestHeaders
{
    /**
     * @throws RestInputRefused with idempotency_key_required when the request has no idempotency
     *                          key, and with the header's code when a header is sent twice or the
     *                          dry run is not true or false
     */
    public function envelope(Request $request): string
    {
        $fields = new stdClass;
        $key = $this->header($request, OpenApiJson::IDEMPOTENCY_KEY, ErrorCode::IdempotencyKeyRequired);

        if ($key === null) {
            throw RestInputRefused::header(ErrorCode::IdempotencyKeyRequired, sprintf('The request has no %s header. A command through REST needs an idempotency key of 1 to 255 visible ASCII characters.', OpenApiJson::IDEMPOTENCY_KEY));
        }

        $fields->idempotency_key = $key;
        $waitLevel = $this->header($request, OpenApiJson::WAIT_LEVEL, ErrorCode::RequestHeaderInvalid);
        $dryRun = $this->header($request, OpenApiJson::DRY_RUN, ErrorCode::RequestHeaderInvalid);
        $correlationId = $this->header($request, OpenApiJson::CORRELATION_ID, ErrorCode::RequestHeaderInvalid);

        if ($waitLevel !== null) {
            $fields->wait_level = $waitLevel;
        }

        if ($dryRun !== null) {
            $fields->dry_run = match ($dryRun) {
                'true' => true,
                'false' => false,
                default => throw RestInputRefused::header(ErrorCode::RequestHeaderInvalid, sprintf('The header %s is "%s". It is true or false.', OpenApiJson::DRY_RUN, $dryRun)),
            };
        }

        if ($correlationId !== null) {
            $fields->correlation_id = $correlationId;
        }

        return JsonText::encode($fields);
    }

    /**
     * The one value of a header, or null when the request has none.
     *
     * @throws RestInputRefused with the code given when the header is sent more than once
     */
    private function header(Request $request, string $name, ErrorCode $code): ?string
    {
        $values = $request->headers->all($name);

        if (count($values) > 1) {
            throw RestInputRefused::header($code, sprintf('The header %s is sent %d times. Send it once.', $name, count($values)));
        }

        $value = $values[0] ?? null;

        return is_string($value) ? $value : null;
    }
}
