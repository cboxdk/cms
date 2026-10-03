<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionData;

/**
 * The telemetry of the panel's contributions, per addon (GUARDRAILS 5, PRD 13.4), through the
 * Telemetry contract, with ids, names, codes and counts only, never a value or a message
 * (invariant 10):
 *
 * - DATA_DURATION, a histogram in milliseconds of each data query a contribution reads, and
 *   DATA_ERRORS, a counter of those that did not answer, each with the addon, the contribution,
 *   the point, the page, the query and its version, the outcome and, for a rejection, the catalog
 *   code of its first error or, for a refusal, its reason;
 * - WITHHELD, a counter of what the panel left off a page it rendered, with the page and the
 *   reason (Withheld), and the addon, contribution and point when it was one contribution.
 *
 * The query pipeline records each data query's own span and metrics too.
 */
#[Experimental]
final readonly class ContributionTelemetry
{
    public const string DATA_DURATION = 'cms.panel.data.duration';

    public const string DATA_ERRORS = 'cms.panel.data.errors';

    public const string WITHHELD = 'cms.panel.contributions.withheld';

    public const string ADDON = 'cms.addon';

    public const string CONTRIBUTION = 'cms.panel.contribution';

    public const string POINT = 'cms.panel.point';

    public const string PAGE = 'cms.panel.page';

    public const string QUERY = 'cms.action';

    public const string QUERY_VERSION = 'cms.action.version';

    public const string OUTCOME = 'cms.outcome';

    public const string ERROR_CODE = 'cms.error.code';

    public const string REASON = 'cms.panel.reason';

    public function __construct(private Telemetry $telemetry) {}

    /**
     * Records one data query of a contribution, which took the nanoseconds given.
     */
    public function data(PageName $page, ActiveFill $fill, ContributionData $data, int $nanoseconds): void
    {
        $query = $fill->data();
        $attributes = [
            ...$this->contribution($page, $fill->point, $fill->fill->contribution),
            Attribute::of(self::OUTCOME, $data->outcome->value),
        ];

        if ($query instanceof CommandRef) {
            $attributes[] = Attribute::of(self::QUERY, $query->name->value);
            $attributes[] = Attribute::of(self::QUERY_VERSION, $query->version);
        }

        if ($data->code instanceof ErrorCode) {
            $attributes[] = Attribute::of(self::ERROR_CODE, $data->code->value);
        }

        if ($data->refusal instanceof DataRefusal) {
            $attributes[] = Attribute::of(self::REASON, $data->refusal->value);
        }

        $metrics = new Attributes(...$attributes);
        $this->telemetry->histogram(new HistogramRecord(new TelemetryName(self::DATA_DURATION), max(0, $nanoseconds) / 1_000_000, MetricUnit::Milliseconds, $metrics));

        if ($data->outcome !== DataOutcome::Answered) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(self::DATA_ERRORS), 1, $metrics));
        }
    }

    /**
     * Records contributions left off a page: all of them, or the one named.
     */
    public function withheld(PageName $page, Withheld $reason, ?PointId $point = null, ?ContributionId $contribution = null): void
    {
        $attributes = $point instanceof PointId && $contribution instanceof ContributionId
            ? $this->contribution($page, $point, $contribution)
            : [Attribute::of(self::PAGE, $page->value)];
        $attributes[] = Attribute::of(self::REASON, $reason->value);

        $this->telemetry->counter(new CounterRecord(new TelemetryName(self::WITHHELD), 1, new Attributes(...$attributes)));
    }

    /**
     * @return list<Attribute>
     */
    private function contribution(PageName $page, PointId $point, ContributionId $contribution): array
    {
        return [
            Attribute::of(self::ADDON, $contribution->namespace()->value),
            Attribute::of(self::CONTRIBUTION, $contribution->value),
            Attribute::of(self::POINT, $point->toString()),
            Attribute::of(self::PAGE, $page->value),
        ];
    }
}
