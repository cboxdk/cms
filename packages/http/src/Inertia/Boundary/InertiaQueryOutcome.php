<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Illuminate\Http\Request;
use Inertia\ResponseFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Inertia profile's answer to a read (GUARDRAILS 2.1: errors in page props): the page
 * component the route names, rendered with the read in its props. An answered read gives the
 * result in the prop RESULT, as the query's result codec writes it at the classification access the
 * query pipeline answered with, the same document REST answers with, and PROBLEM_PROP null; a
 * rejected read, or a document the codec refused, gives RESULT null and the problem details
 * (problem.v1.json) with every catalog code and path in InertiaOutcome::PROBLEM_PROP, the prop a
 * rejected write leaves too.
 *
 * Every answer, results and problems alike, the HTML page and the X-Inertia JSON, carries
 * CACHE_CONTROL, as REST's answers do: a read's props can hold personal fields (PRD 12.2), which
 * no browser cache, back/forward cache or shared proxy may keep.
 */
#[Internal]
final readonly class InertiaQueryOutcome
{
    /** The page prop that holds the result's document. */
    public const string RESULT = 'result';

    /** The Cache-Control of every answer. */
    public const string CACHE_CONTROL = 'no-store, private';

    public function __construct(
        private ResponseFactory $inertia,
        private ProblemCodecV1 $problems,
    ) {}

    public function read(Request $request, string $component, QueryResult $result, QueryCodec $codec): Response
    {
        if (! $result->result instanceof Result) {
            return $this->rejected($request, $component, ...$result->errors);
        }

        // The query pipeline answers with the result of the query the codec is registered for, the
        // class the codec writes; the codec's template is covariant, so its parameter reads never.
        // @phpstan-ignore argument.type (the result of this codec's query, see above)
        $document = $codec->result->encode($result->result, $result->access);

        return $this->answer($request, $component, [
            self::RESULT => InertiaProps::document($document),
            InertiaOutcome::PROBLEM_PROP => null,
        ]);
    }

    public function refused(Request $request, string $component, InertiaQueryRefused $refused): Response
    {
        return $this->rejected($request, $component, $refused->error);
    }

    private function rejected(Request $request, string $component, CatalogError $first, CatalogError ...$more): Response
    {
        $problem = Problem::of($first->code, $first->message, [$first, ...array_values($more)]);

        return $this->answer($request, $component, [
            self::RESULT => null,
            InertiaOutcome::PROBLEM_PROP => InertiaProps::document($this->problems->encode($problem, ClassificationAccess::Public)),
        ]);
    }

    /**
     * The page rendered with the props for the request, never to be stored.
     *
     * @param  array<string, mixed>  $props
     */
    private function answer(Request $request, string $component, array $props): Response
    {
        $response = $this->inertia->render($component, $props)->toResponse($request);
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);

        return $response;
    }
}
