<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelStories\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Domain\Dto\StoryPoint;

/**
 * The installation's panel points as cms:build compiled them into panel.php, each with its sample
 * props and schema, for the stories of the panel's points.
 */
#[Internal]
interface PanelPointSource
{
    /**
     * Every point, sorted by name and version.
     *
     * @return list<StoryPoint>
     *
     * @throws GenerationFailed with generate_registry_unreadable, or generate_schema_invalid for a schema that has no sample
     */
    public function points(): array;
}
