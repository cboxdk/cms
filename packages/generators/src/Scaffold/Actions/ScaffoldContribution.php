<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Domain\PanelPointSource;
use Cbox\Cms\Generators\PanelTypes\Domain\AddonUiSource;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\UiContribution;
use Cbox\Cms\Generators\Scaffold\Domain\ContributionStub;
use Cbox\Cms\Generators\Scaffold\Domain\DocumentSamples;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ContributionRequest;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\RegistrationEntry;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldReport;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldResult;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\StubContribution;
use Cbox\Cms\Generators\Scaffold\Domain\IndexModule;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldKind;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldOutput;
use Cbox\Cms\Generators\Scaffold\Domain\StubPoints;

/**
 * cms:make:panel fill|action|check|step (PRD 13.4, section 7 of the panel extension
 * architecture): scaffolds one contribution of an installed addon. For a contribution cms:build
 * compiled from the manifest, the registry gives its point, command, query, severity, position
 * and paths; for one the manifest does not have yet, the request gives them, and the notes say
 * the line to add to the manifest and what to run next. It writes the stub and its test where the
 * addon has none, adds the contribution to the registration module and the ids module, and asks
 * for them to be added by hand when the addon laid them out otherwise.
 */
#[Internal]
final readonly class ScaffoldContribution
{
    public function __construct(
        private AddonUiSource $addons,
        private PanelPointSource $points,
        private DocumentSamples $samples,
        private ScaffoldOutput $output,
    ) {}

    /**
     * @throws GenerationFailed
     */
    public function scaffold(ContributionRequest $request): ScaffoldReport
    {
        $addon = $this->addons->addon($request->namespace);
        $points = StubPoints::of($this->points->points());
        $compiled = null;

        foreach ($addon->contributions as $contribution) {
            if ($contribution->id->equals($request->id)) {
                $compiled = $contribution;
            }
        }

        $stub = $compiled instanceof UiContribution
            ? $this->compiledStub($request, $compiled, $points)
            : $this->requestedStub($request, $points);
        $files = ContributionStub::files($stub);
        $updates = [];
        $notes = [];

        if ($stub->kind->runsCode()) {
            $entry = ContributionStub::registration($stub);

            if ($entry instanceof RegistrationEntry) {
                $this->register($addon->root, $request, $entry, $files, $updates, $notes);
            }
        }

        if (! $stub->compiled) {
            $notes[] = sprintf(
                "Add the contribution to the manifest's PanelContributions in the addon's service provider: %s. Then run cms:build and cms:panel:types %s, so the generated types have it.",
                ContributionStub::manifestLine($stub),
                $request->namespace->value,
            );
        }

        return $this->output->write($addon->root, new ScaffoldResult($files, $updates, $notes));
    }

    /**
     * @throws GenerationFailed with generate_panel_contribution_mismatch
     */
    private function compiledStub(ContributionRequest $request, UiContribution $compiled, StubPoints $points): StubContribution
    {
        $kind = ScaffoldKind::forPointKind($compiled->kind);

        if ($kind !== $request->kind) {
            throw GenerationFailed::because(GenerateErrorCode::PanelContributionMismatch, sprintf(
                'The registry has the contribution %s as a %s on %s, not as a %s. Give the kind the registry has, or another id.',
                $request->id->value,
                $compiled->kind->value,
                $compiled->point->point->toString(),
                $request->kind->value,
            ));
        }

        $command = $compiled->command?->ref;
        $query = $compiled->data?->ref;

        return new StubContribution(
            $request->id,
            $kind,
            $points->get($compiled->point->point),
            true,
            $command,
            $command instanceof CommandRef ? $this->samples->command($command) : null,
            $query,
            $query instanceof CommandRef ? $this->samples->result($query) : null,
            $compiled->severity ?? Severity::Warning,
            $compiled->position ?? StepPosition::BeforeSubmit,
            $compiled->patches,
        );
    }

    /**
     * @throws GenerationFailed with generate_panel_point_unknown or generate_panel_contribution_mismatch
     */
    private function requestedStub(ContributionRequest $request, StubPoints $points): StubContribution
    {
        if (! $request->point instanceof PointId) {
            throw GenerationFailed::because(GenerateErrorCode::PanelContributionMismatch, sprintf(
                'The registry has no contribution %s, so the point it is on is needed: give --point=<name>@<version>, such as --point=account.me.sections@1.',
                $request->id->value,
            ));
        }

        $point = $points->get($request->point);

        if ($point->kind !== $request->kind->pointKind()) {
            throw GenerationFailed::because(GenerateErrorCode::PanelContributionMismatch, sprintf(
                'The point %s is of the kind %s, which takes no %s. Give a point of the kind %s.',
                $point->id->toString(),
                $point->kind->value,
                $request->kind->value,
                $request->kind->pointKind()->value,
            ));
        }

        return new StubContribution(
            $request->id,
            $request->kind,
            $point,
            false,
            $request->command,
            $request->command instanceof CommandRef ? $this->samples->command($request->command) : null,
            $request->query,
            $request->query instanceof CommandRef ? $this->samples->result($request->query) : null,
            $request->severity,
            $request->position,
            $request->patches,
        );
    }

    /**
     * Adds the entry to the registration module and the id to the ids module, writing each anew
     * where the addon has none.
     *
     * @param  list<GeneratedFile>  $files
     * @param  list<GeneratedFile>  $updates
     * @param  list<string>  $notes
     */
    private function register(string $root, ContributionRequest $request, RegistrationEntry $entry, array &$files, array &$updates, array &$notes): void
    {
        $index = $this->output->read($root, IndexModule::INDEX);

        if ($index === null) {
            $files[] = IndexModule::index($request->namespace, [$entry]);
            $files[] = IndexModule::indexTest($request->namespace);
        } else {
            $updated = IndexModule::withEntry($index, $entry);

            if ($updated === null) {
                $notes[] = sprintf('Add the contribution to the registration in %s by hand: %s: %s.', IndexModule::INDEX, $entry->id, $entry->expression);
            } elseif ($updated !== $index) {
                $updates[] = new GeneratedFile(IndexModule::INDEX, $updated);
            }
        }

        $ids = $this->output->read($root, IndexModule::IDS);
        $known = $ids === null ? [] : IndexModule::idsOf($ids);

        if ($known === null) {
            $notes[] = sprintf('Add %s to the ids in %s by hand.', $entry->id, IndexModule::IDS);

            return;
        }

        if (! in_array($entry->id, $known, true)) {
            $updates[] = IndexModule::ids($request->namespace, [...$known, $entry->id]);
        }
    }
}
