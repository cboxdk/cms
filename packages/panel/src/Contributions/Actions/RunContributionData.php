<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\DataRefusal;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionData;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionDataCall;
use Throwable;

/**
 * Runs the data query of an active contribution (PRD 13.4): through the QueryPipeline as the
 * viewer, from the credential the request carried, never as the addon, so the viewer's grants,
 * row level security and the query budget of an actor (cbox-cms.queries.budgets.actor) all hold;
 * with the call's ceiling at the classification access the fill was handed, the lower of the
 * viewer's and the addon's reads, so the read, its stripping and its audit hold to it; and gives
 * the result with the access the read had, which the query's result codec writes it at. A query the pipeline
 * rejects, such as one over budget, or that throws, gives no data, so the contribution renders its
 * error state and the page still answers; refused() records one the panel could not run. Each
 * outcome is recorded per addon in ContributionTelemetry.
 */
#[Experimental]
final readonly class RunContributionData
{
    public function __construct(
        private QueryPipeline $pipeline,
        private Stopwatch $stopwatch,
        private ContributionTelemetry $telemetry,
    ) {}

    public function run(ContributionDataCall $call): ContributionData
    {
        $start = $this->stopwatch->nanoseconds();

        try {
            $result = $this->pipeline->run(new QueryCall($call->query, $call->credential, Surface::Inertia, $call->correlationId, $call->fill->access));
            $data = $result->result instanceof Result && $result->isAnswered()
                ? ContributionData::answered($result->result, $result->access)
                : ContributionData::rejected($result->errors[0]->code ?? ErrorCode::Unauthorized);
        } catch (Throwable $failure) {
            $data = ContributionData::failed($failure);
        }

        $this->telemetry->data($call->page, $call->fill, $data, $this->stopwatch->nanoseconds() - $start);

        return $data;
    }

    /**
     * Records that the panel did not run the fill's data query, and gives no data.
     */
    public function refused(PageName $page, ActiveFill $fill, DataRefusal $refusal): ContributionData
    {
        $data = ContributionData::refused($refusal);
        $this->telemetry->data($page, $fill, $data, 0);

        return $data;
    }
}
