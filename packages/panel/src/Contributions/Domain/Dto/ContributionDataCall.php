<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * One run of a contribution's data query (PRD 13.4): the page and the active fill it is for, the
 * query read from the point's props, the viewer's credential, which the query pipeline verifies
 * again, and the correlation id of the request when it has one.
 */
#[Experimental]
final readonly class ContributionDataCall
{
    public function __construct(
        public PageName $page,
        public ActiveFill $fill,
        public Query $query,
        public TransportCredential $credential,
        public ?CorrelationId $correlationId = null,
    ) {}
}
