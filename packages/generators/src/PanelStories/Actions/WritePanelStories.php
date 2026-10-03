<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelStories\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Domain\Dto\PanelStoriesRequest;
use Cbox\Cms\Generators\PanelStories\Domain\PanelPointSource;
use Cbox\Cms\Generators\PanelStories\Domain\PanelStoriesModule;

/**
 * Writes the stories of the panel's points (section 2.7 of the panel extension architecture): one
 * story per point of the installation's panel.php, so no point exists without a story, each
 * rendering the point's host with its sample props and the contributions compiled for it, and an
 * overview of every point.
 */
#[Internal]
final readonly class WritePanelStories
{
    public function __construct(
        private PanelPointSource $points,
        private GeneratedOutput $output,
    ) {}

    /**
     * @throws GenerationFailed
     */
    public function write(PanelStoriesRequest $request): WriteReport
    {
        return $this->output->write($request->root, PanelStoriesModule::result($this->points->points()));
    }
}
