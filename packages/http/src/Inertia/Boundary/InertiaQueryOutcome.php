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
use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * The Inertia profile's answer to a read (GUARDRAILS 2.1: errors in page props): the page
 * component the route names, rendered with the read in its props. An answered read gives the
 * result in the prop RESULT, as the query's result codec writes it at the classification access the
 * query pipeline answered with, the same document REST answers with, and PROBLEM_PROP null; a
 * rejected read, or a document the codec refused, gives RESULT null and the problem details
 * (problem.v1.json) with every catalog code and path in InertiaOutcome::PROBLEM_PROP, the prop a
 * rejected write leaves too.
 */
#[Internal]
final readonly class InertiaQueryOutcome
{
    /** The page prop that holds the result's document. */
    public const string RESULT = 'result';

    public function __construct(
        private ResponseFactory $inertia,
        private ProblemCodecV1 $problems,
    ) {}

    public function read(string $component, QueryResult $result, QueryCodec $codec): Response
    {
        if (! $result->result instanceof Result) {
            return $this->rejected($component, ...$result->errors);
        }

        // The query pipeline answers with the result of the query the codec is registered for, the
        // class the codec writes; the codec's template is covariant, so its parameter reads never.
        // @phpstan-ignore argument.type (the result of this codec's query, see above)
        $document = $codec->result->encode($result->result, $result->access);

        return $this->inertia->render($component, [
            self::RESULT => InertiaProps::document($document),
            InertiaOutcome::PROBLEM_PROP => null,
        ]);
    }

    public function refused(string $component, InertiaQueryRefused $refused): Response
    {
        return $this->rejected($component, $refused->error);
    }

    private function rejected(string $component, CatalogError $first, CatalogError ...$more): Response
    {
        $problem = Problem::of($first->code, $first->message, [$first, ...array_values($more)]);

        return $this->inertia->render($component, [
            self::RESULT => null,
            InertiaOutcome::PROBLEM_PROP => InertiaProps::document($this->problems->encode($problem, ClassificationAccess::Public)),
        ]);
    }
}
