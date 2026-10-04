<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldKind;

/**
 * What cms:make:panel takes: the kind of contribution, the addon, the contribution's id and, for
 * a contribution cms:build has not compiled yet, the point it fills, the command a check, a step
 * or an action is on, the data query of a fill, a check's severity, a step's position and the
 * paths it patches. For a compiled contribution the registry gives these.
 *
 * @param  list<string>  $patches
 */
#[Internal]
final readonly class ContributionRequest
{
    /**
     * @param  list<string>  $patches
     */
    public function __construct(
        public ScaffoldKind $kind,
        public AddonNamespace $namespace,
        public ContributionId $id,
        public ?PointId $point = null,
        public ?CommandRef $command = null,
        public ?CommandRef $query = null,
        public Severity $severity = Severity::Warning,
        public StepPosition $position = StepPosition::BeforeSubmit,
        public array $patches = [],
    ) {}
}
