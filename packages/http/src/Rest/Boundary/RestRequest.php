<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Rest\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Http\Credentials\Boundary\BearerCredential;
use Cbox\Cms\Http\Rest\Domain\Dto\RestQueryInput;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;

/**
 * Reads a request of a compiled REST route (GUARDRAILS 2.1, 2.2, PRD 6.1, 8.8). The route names the
 * command or query and its version in its defaults (self::NAME and self::VERSION),
 * which pick the codec that reads it.
 *
 * - A write: the credential of the Authorization header (BearerCredential), the envelope from the
 *   headers, read through the envelope's generated codec (envelope.v1.json), and the body, which is
 *   the command's document, left for the command's codec that RunExposedCommand reads it with once
 *   the caller's classification access is known. The envelope's headers are Idempotency-Key, which
 *   REST requires (idempotency_key_required when it is missing, repeated or not a key), and
 *   Cbox-Wait-Level, Cbox-Dry-Run (true or false) and Cbox-Correlation-Id, each with the envelope's
 *   default when it is left out (request_header_invalid when one is repeated or breaks its rule).
 *   The on-behalf-of chain comes from the credential alone, and REST sends no provenance.
 * - A read: the credential, which may be missing (the anonymous principal), and the query's document
 *   from the query parameter RestRoute::QUERY_PARAMETER, an empty object when it is left out, read
 *   with the query's codec at public classification access, because a query holds no classified
 *   content. A document the codec refuses is refused with its code at the path below `query`.
 */
#[Internal]
final readonly class RestRequest
{
    /** The route default that holds the command's or query's name. */
    public const string NAME = 'cbox_cms_name';

    /** The route default that holds the command's or query's version. */
    public const string VERSION = 'cbox_cms_version';

    /** The query document when the request leaves the parameter out. */
    private const string EMPTY_QUERY = '{}';

    public function __construct(
        private CommandCodecs $commands,
        private QueryCodecs $queries,
        private EnvelopeCodecV1 $envelopes,
        private RestHeaders $headers = new RestHeaders,
    ) {}

    /**
     * @throws RestInputRefused when a header of the envelope is missing or breaks its rule
     */
    public function command(Request $request): ExposedCall
    {
        [$name, $version] = $this->target($request);
        $document = $this->headers->envelope($request);

        try {
            $envelope = $this->envelopes->decode($document, ClassificationAccess::Public);
        } catch (DecodingFailed $failed) {
            throw $this->refusedHeader($failed);
        }

        return new ExposedCall(Surface::Rest, BearerCredential::of($request), $envelope, $this->commands->for($name, $version), $request->getContent());
    }

    /**
     * @throws RestInputRefused when the query document is not one the query's codec reads
     */
    public function query(Request $request): RestQueryInput
    {
        [$name, $version] = $this->target($request);
        $codec = $this->queries->for($name, $version);
        $document = $request->query->all()[RestRoute::QUERY_PARAMETER] ?? self::EMPTY_QUERY;
        $at = new FieldPath(RestRoute::QUERY_PARAMETER);

        if (! is_string($document)) {
            throw RestInputRefused::document(DecodingFailed::malformed(sprintf('The query parameter %s holds more than one value; send the query\'s document as one JSON object.', RestRoute::QUERY_PARAMETER)), $at);
        }

        try {
            $query = $codec->query->decode($document, ClassificationAccess::Public);
        } catch (DecodingFailed $failed) {
            throw RestInputRefused::document($failed, $failed->path instanceof FieldPath ? $at->then(...$failed->path->segments) : $at);
        }

        return new RestQueryInput(new QueryCall($query, BearerCredential::of($request)), $codec);
    }

    /**
     * The name and version of the route's command or query.
     *
     * @return array{CommandName, int}
     */
    private function target(Request $request): array
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->parameter(self::NAME) : null;
        $version = $route instanceof Route ? $route->parameter(self::VERSION) : null;

        if (! is_string($name) || ! is_string($version) || preg_match('/\A[1-9][0-9]{0,8}\z/', $version) !== 1) {
            throw new LogicException(sprintf('The REST route has no command or query in its defaults %s and %s; register it with RestRoutes::register().', self::NAME, self::VERSION));
        }

        return [new CommandName($name), (int) $version];
    }

    /**
     * The refusal of an envelope header the envelope's codec did not accept, naming the header.
     */
    private function refusedHeader(DecodingFailed $failed): RestInputRefused
    {
        $field = $failed->path instanceof FieldPath ? $failed->path->segments[0] : null;
        $reason = rtrim($failed->reason, '.');

        return match ($field) {
            'idempotency_key' => RestInputRefused::header(ErrorCode::IdempotencyKeyRequired, sprintf('The header %s %s.', OpenApiJson::IDEMPOTENCY_KEY, $reason), $failed),
            'wait_level' => RestInputRefused::header(ErrorCode::RequestHeaderInvalid, sprintf('The header %s %s.', OpenApiJson::WAIT_LEVEL, $reason), $failed),
            'correlation_id' => RestInputRefused::header(ErrorCode::RequestHeaderInvalid, sprintf('The header %s %s.', OpenApiJson::CORRELATION_ID, $reason), $failed),
            default => throw new LogicException(sprintf('The envelope of the REST headers was refused at %s: %s', $failed->path?->toString() ?? 'the document', $failed->reason), 0, $failed),
        };
    }
}
