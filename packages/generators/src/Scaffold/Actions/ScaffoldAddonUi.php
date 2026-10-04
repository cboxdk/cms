<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Domain\PanelPointSource;
use Cbox\Cms\Generators\PanelTypes\Actions\WritePanelTypes;
use Cbox\Cms\Generators\PanelTypes\Domain\AddonUiSource;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\PanelTypesRequest;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\UiContribution;
use Cbox\Cms\Generators\Scaffold\Domain\AddonUiFiles;
use Cbox\Cms\Generators\Scaffold\Domain\ContributionStub;
use Cbox\Cms\Generators\Scaffold\Domain\DocumentSamples;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\AddonUiRequest;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\RegistrationEntry;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldReport;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldResult;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\StubContribution;
use Cbox\Cms\Generators\Scaffold\Domain\IndexModule;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldKind;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldOutput;
use Cbox\Cms\Generators\Scaffold\Domain\StubPoints;

/**
 * cms:make:addon-ui (PRD 13.4, section 7 of the panel extension architecture): scaffolds the
 * panel UI of an installed addon into its package, from the contributions cms:build compiled from
 * its manifest. It writes the generated types first, as cms:panel:types does, then, each only
 * where the addon has no such file, the files beside its code, the registration module with an
 * entry per contribution that runs code, its test, a stub and a test per fill, check and step,
 * and the PHP test that runs the testkit's PanelContributionsContract; it writes the ids module
 * anew, because the registry owns it. A contribution of a kind without a stub, such as a
 * decorator, gets a note instead.
 */
#[Internal]
final readonly class ScaffoldAddonUi
{
    public function __construct(
        private AddonUiSource $addons,
        private PanelPointSource $points,
        private DocumentSamples $samples,
        private ScaffoldOutput $output,
        private WritePanelTypes $types,
    ) {}

    /**
     * @throws GenerationFailed
     */
    public function scaffold(AddonUiRequest $request): ScaffoldReport
    {
        $addon = $this->addons->addon($request->namespace);
        $this->types->write(new PanelTypesRequest($request->namespace));
        $package = $this->output->package($addon->root);
        $points = StubPoints::of($this->points->points());
        $files = AddonUiFiles::files($request->namespace, $package);
        $entries = [];
        $ids = [];
        $notes = [];

        foreach ($addon->contributions as $contribution) {
            $ids[] = $contribution->id->value;
            $kind = ScaffoldKind::forPointKind($contribution->kind);

            if (! $kind instanceof ScaffoldKind || ! $kind->runsCode()) {
                $notes[] = sprintf(
                    'The contribution %s is of the kind %s, which cms:make:panel writes no stub for: register it in %s by hand.',
                    $contribution->id->value,
                    $contribution->kind->value,
                    IndexModule::INDEX,
                );

                continue;
            }

            $stub = $this->stub($contribution, $kind, $points);
            $entry = ContributionStub::registration($stub);

            if ($entry instanceof RegistrationEntry) {
                $entries[] = $entry;
            }

            array_push($files, ...ContributionStub::files($stub));
        }

        $files[] = IndexModule::index($request->namespace, $entries);
        $files[] = IndexModule::indexTest($request->namespace);
        $notes[] = sprintf('Install the dependencies with npm install, then run npm run typecheck, npm run lint and npm run test; npm run build writes dist/panel, which the manifest names as the bundle.');

        return $this->output->write($addon->root, new ScaffoldResult(
            $this->sorted($files),
            [IndexModule::ids($request->namespace, $ids)],
            $notes,
        ));
    }

    /**
     * @throws GenerationFailed
     */
    private function stub(UiContribution $contribution, ScaffoldKind $kind, StubPoints $points): StubContribution
    {
        $command = $contribution->command?->ref;
        $query = $contribution->data?->ref;

        return new StubContribution(
            $contribution->id,
            $kind,
            $points->get($contribution->point->point),
            true,
            $command,
            $command instanceof CommandRef ? $this->samples->command($command) : null,
            $query,
            $query instanceof CommandRef ? $this->samples->result($query) : null,
            $contribution->severity ?? Severity::Warning,
            $contribution->position ?? StepPosition::BeforeSubmit,
            $contribution->patches,
        );
    }

    /**
     * @param  list<GeneratedFile>  $files
     * @return list<GeneratedFile>
     */
    private function sorted(array $files): array
    {
        usort($files, static fn (GeneratedFile $a, GeneratedFile $b): int => strcmp($a->path, $b->path));

        return $files;
    }
}
