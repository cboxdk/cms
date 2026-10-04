<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Reports;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Actions\RecordCspReports;
use Cbox\Cms\Panel\Boundary\CspReportInput;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Takes a browser's report of a violation of the panel's Content-Security-Policy and counts it
 * (RecordCspReports). It answers 204 whatever the report holds, and holds no logic of its own.
 */
#[Internal]
final readonly class CspReportController
{
    public function __construct(
        private CspReportInput $input,
        private RecordCspReports $reports,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->reports->record($this->input->violations($request));

        return new Response('', 204, ['Cache-Control' => 'no-store']);
    }
}
