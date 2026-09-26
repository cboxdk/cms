<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorReport;

/**
 * The document `cms:doctor --json` prints, version 1, described by the JSON Schema
 * packages/contracts/resources/schemas/doctor.v1.json (GUARDRAILS 2.6).
 *
 * Every key is always present, a value that does not apply is null, keys are sorted, and the
 * checks keep the order they ran in, so the same results give the same bytes.
 */
#[Internal]
final readonly class DoctorReportJson
{
    public const int VERSION = 1;

    public static function encode(DoctorReport $report): string
    {
        $document = [
            'checks' => array_map(self::check(...), $report->results),
            'dev' => $report->dev,
            'exit_code' => $report->exit->value,
            'status' => $report->exit->status(),
            'version' => self::VERSION,
        ];

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, bool|string|null>
     */
    private static function check(CheckResult $result): array
    {
        return [
            'blocking' => $result->blocking,
            'cause' => $result->cause,
            'code' => $result->code,
            'explanation' => $result->explanation,
            'failure' => $result->failure?->value,
            'fix' => $result->fix,
            'id' => $result->id->value,
            'status' => $result->status->value,
        ];
    }
}
