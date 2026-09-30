<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Rest\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Illuminate\Http\Response;

/**
 * The REST surface's answer to a call (GUARDRAILS 2.1: JSON and problem details; PRD 6.1, 8.8),
 * every body written by a generated codec (GUARDRAILS 2.2):
 *
 * - A write that committed, or ran dry, answers 200 with the receipt (receipt.v1.json). One that
 *   committed but did not reach its wait level answers 202 with the receipt, whose outcome is
 *   committed_wait_timeout: the command committed and must not be sent again as a new one.
 * - A read that was answered answers 200 with the result, written by the query's result codec
 *   with the classification access of the read's principal.
 * - A rejected call, and a request the surface could not read, answer with a problem details
 *   document (RFC 9457, problem.v1.json) as application/problem+json, with the HTTP status the
 *   catalog gives the code of the first error, the one that decided it, and every error with the
 *   path of its field in the document the caller sent.
 *
 * No answer may be stored by a cache: each is the outcome of one call by one principal.
 */
#[Internal]
final readonly class RestResponse
{
    public const string JSON = 'application/json';

    public const string CACHE_CONTROL = 'no-store, private';

    public function __construct(
        private ReceiptCodecV1 $receipts,
        private ProblemCodecV1 $problems,
    ) {}

    public function written(WriteResult $result): Response
    {
        if ($result->errors !== []) {
            return $this->problem(...$result->errors);
        }

        return $this->json(
            $this->receipts->encode($result->receipt, ClassificationAccess::Public),
            $result->receipt->outcome === Outcome::CommittedWaitTimeout ? Response::HTTP_ACCEPTED : Response::HTTP_OK,
        );
    }

    public function read(QueryResult $result, QueryCodec $codec): Response
    {
        if (! $result->result instanceof Result) {
            return $this->problem(...$result->errors);
        }

        // The query pipeline answers with the result of the query the codec is registered for, the
        // class the codec writes; the codec's template is covariant, so its parameter reads never.
        // @phpstan-ignore argument.type (the result of this codec's query, see above)
        $body = $codec->result->encode($result->result, $result->access);

        return $this->json($body, Response::HTTP_OK);
    }

    public function refused(RestInputRefused $refused): Response
    {
        return $this->problem($refused->error);
    }

    private function problem(CatalogError $first, CatalogError ...$more): Response
    {
        $problem = Problem::of($first->code, $first->message, [$first, ...array_values($more)]);

        return new Response($this->problems->encode($problem, ClassificationAccess::Public), $problem->status, [
            'Cache-Control' => self::CACHE_CONTROL,
            'Content-Type' => OpenApiJson::PROBLEM_MEDIA_TYPE,
        ]);
    }

    private function json(string $body, int $status): Response
    {
        return new Response($body, $status, [
            'Cache-Control' => self::CACHE_CONTROL,
            'Content-Type' => self::JSON,
        ]);
    }
}
