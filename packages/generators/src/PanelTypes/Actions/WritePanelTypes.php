<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Domain\AddonUiSource;
use Cbox\Cms\Generators\PanelTypes\Domain\ContributionsModule;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\PanelTypesRequest;

/**
 * cms:panel:types (PRD 13.4, section 4.4 of the panel extension architecture): reads an installed
 * addon's UI from the registry cms:build compiled and writes its contributions.ts below the
 * addon's package, only when the bytes differ, removing every other file in the directory it owns.
 * Nothing is written unless every schema could be typed.
 */
#[Internal]
final readonly class WritePanelTypes
{
    public function __construct(
        private AddonUiSource $addons,
        private GeneratedOutput $output,
    ) {}

    /**
     * @throws GenerationFailed
     */
    public function write(PanelTypesRequest $request): WriteReport
    {
        $addon = $this->addons->addon($request->namespace);

        return $this->output->write($addon->root, ContributionsModule::result($addon));
    }
}
