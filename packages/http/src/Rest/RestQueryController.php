<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Rest;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Http\Rest\Boundary\RestInputRefused;
use Cbox\Cms\Http\Rest\Boundary\RestRequest;
use Cbox\Cms\Http\Rest\Boundary\RestResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A read through REST (GUARDRAILS 2.1): RestRequest reads the request of a compiled route into the
 * query call, the QueryPipeline runs it, and RestResponse translates the typed result: the result
 * through the query's codec, or problem details. It holds no logic of its own; the Arch suite
 * keeps it to actions, DTOs and Boundary.
 */
#[Internal]
final readonly class RestQueryController
{
    public function __construct(
        private RestRequest $requests,
        private QueryPipeline $pipeline,
        private RestResponse $responses,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $input = $this->requests->query($request);
        } catch (RestInputRefused $refused) {
            return $this->responses->refused($refused);
        }

        return $this->responses->read($this->pipeline->run($input->call), $input->codec);
    }
}
