<?php

declare(strict_types=1);

namespace Examples\Unit\Hooks;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * The command the hooks on this page run for, version 1 of story.publish.
 */
#[Command('story.publish', version: 1)]
final readonly class PublishStory implements CommandInput {}
