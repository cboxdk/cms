<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelStories\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Where cms:panel:stories writes the panel points' stories: below the root of the repository that
 * holds js/panel, the root package's directory.
 */
#[Internal]
final readonly class PanelStoriesRequest
{
    public function __construct(public string $root) {}
}
