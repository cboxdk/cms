<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldKind;

/**
 * One contribution a stub is written for: its id, its kind, its point, the command a check, a
 * step or an action is on with a sample document, the data query of a fill with a sample result,
 * a check's severity, and a step's position and the paths it patches. `compiled` says whether
 * cms:build has compiled it already, so the manifest has it.
 */
#[Internal]
final readonly class StubContribution
{
    /**
     * @param  list<string>  $patches
     */
    public function __construct(
        public ContributionId $id,
        public ScaffoldKind $kind,
        public StubPoint $point,
        public bool $compiled,
        public ?CommandRef $command = null,
        public ?Literal $commandSample = null,
        public ?CommandRef $query = null,
        public ?Literal $resultSample = null,
        public Severity $severity = Severity::Warning,
        public StepPosition $position = StepPosition::BeforeSubmit,
        public array $patches = [],
    ) {}
}
