<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Actions;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Panel\Domain\Dto\CspViolation;

/**
 * Records the violations of the panel's Content-Security-Policy a browser reported (GUARDRAILS 5,
 * 6): one count on the counter COUNTER per directive and addon, the addon as its namespace or
 * NO_ADDON for the panel's own files. Nothing a page or a report says is kept beyond those ids
 * (invariant 10).
 */
#[Internal]
final readonly class RecordCspReports
{
    public const string COUNTER = 'cms.panel.csp_violations';

    public const string DIRECTIVE_ATTRIBUTE = 'cms.panel.csp.directive';

    public const string ADDON_ATTRIBUTE = 'cms.panel.addon';

    /** The attribute's value for a violation that belongs to no addon. */
    public const string NO_ADDON = 'none';

    public function __construct(private Telemetry $telemetry) {}

    /**
     * @param  list<CspViolation>  $violations
     */
    public function record(array $violations): void
    {
        $counts = [];

        foreach ($violations as $violation) {
            $addon = $violation->addon instanceof AddonNamespace ? $violation->addon->value : self::NO_ADDON;
            $key = $violation->directive.' '.$addon;
            $counts[$key] = [$violation->directive, $addon, ($counts[$key][2] ?? 0) + 1];
        }

        ksort($counts, SORT_STRING);

        foreach ($counts as [$directive, $addon, $count]) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(self::COUNTER), $count, new Attributes(
                new Attribute(new TelemetryName(self::DIRECTIVE_ATTRIBUTE), $directive),
                new Attribute(new TelemetryName(self::ADDON_ATTRIBUTE), $addon),
            )));
        }
    }
}
