<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Content\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * What a request of the content list asks for (PRD 5.4, 13.4): the type it lists, or null for
 * every type, the release state of the shared variant it is filtered by, or null for every state,
 * and the entry the page starts after, or null for the first page.
 */
#[Internal]
final readonly class ContentTarget
{
    public function __construct(
        public ?TypeEditor $type,
        public ?ReleaseState $state,
        public ?EntryId $after,
    ) {}
}
