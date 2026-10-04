<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Http\Credentials\Boundary\RequestCredential;
use Cbox\Cms\Panel\Domain\Dto\ReadAnswer;
use Illuminate\Http\Request;
use LogicException;

/**
 * The reads of the panel's own pages (PRD 13.4): a page whose props come from a query reads it
 * through the QueryPipeline as the person, from the session credential PanelSessions put on the
 * request, through the Inertia surface, as the Inertia profile reads (InertiaQueryRequest), and
 * shows the answer as the profile answers it (InertiaQueryOutcome): the result as the query's
 * result codec wrote it at the classification access the pipeline answered with, the same document
 * REST answers with, or the problem details (problem.v1.json) of a rejected read with every
 * catalog code. A page never reads a query without a codec: codec() throws for one.
 */
#[Internal]
final readonly class PanelReads
{
    public function __construct(
        private QueryCodecs $codecs,
        private ProblemCodecV1 $problems,
    ) {}

    /**
     * The call of a page's query as the person who signed in.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function call(Request $request, Query $query): QueryCall
    {
        $credential = RequestCredential::of($request);

        if (! $credential instanceof TransportCredential) {
            throw new LogicException(sprintf('The panel reads %s only for a request the panel authenticated.', $query::class));
        }

        return new QueryCall($query, $credential, Surface::Inertia);
    }

    /**
     * The codecs of a page's query.
     */
    public function codec(CommandRef $query): QueryCodec
    {
        return $this->codecs->for($query->name, $query->version);
    }

    /**
     * The answer of the read as the page shows it.
     */
    public function answer(QueryResult $read, QueryCodec $codec): ReadAnswer
    {
        if (! $read->result instanceof Result) {
            $first = $read->errors[0] ?? throw new LogicException('A rejected read carries at least one error.');
            $problem = Problem::of($first->code, $first->message, $read->errors);

            return new ReadAnswer(null, new JsonDocument($this->problems->encode($problem, ClassificationAccess::Public)));
        }

        // The query pipeline answers with the result of the query the codec is registered for, the
        // class the codec writes; the codec's template is covariant, so its parameter reads never.
        // @phpstan-ignore argument.type (the result of this codec's query, see above)
        return new ReadAnswer(new JsonDocument($codec->result->encode($read->result, $read->access)), null);
    }
}
