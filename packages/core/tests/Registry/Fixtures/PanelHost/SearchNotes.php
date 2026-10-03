<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * A query of the host, which no addon's contribution may read its data with.
 */
#[QueryType('notes.search', version: 1)]
final readonly class SearchNotes implements Query
{
    public function __construct(public string $note) {}
}
