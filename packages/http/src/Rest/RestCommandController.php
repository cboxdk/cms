<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Rest;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Http\Rest\Boundary\RestInputRefused;
use Cbox\Cms\Http\Rest\Boundary\RestRequest;
use Cbox\Cms\Http\Rest\Boundary\RestResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A write through REST (GUARDRAILS 2.1): RestRequest reads the request of a compiled route into
 * the call, RunExposedCommand runs it through the command pipeline, and RestResponse translates the
 * typed result: the receipt, or problem details. It holds no logic of its own; the Arch suite
 * keeps it to actions, DTOs and Boundary.
 */
#[Internal]
final readonly class RestCommandController
{
    public function __construct(
        private RestRequest $requests,
        private RunExposedCommand $run,
        private RestResponse $responses,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $call = $this->requests->command($request);
        } catch (RestInputRefused $refused) {
            return $this->responses->refused($refused);
        }

        return $this->responses->written($this->run->run($call));
    }
}
