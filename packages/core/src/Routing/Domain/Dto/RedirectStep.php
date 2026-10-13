<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Core\Routing\Domain\RedirectReason;
use DateTimeImmutable;

/**
 * The resolution ends in a redirect (PRD 5.8, 5.9, 8.10): the absolute URL the page lives at, the
 * origin of the target's site with its node's route and its slug, never built from the request; the
 * placement the URL is of, null when the redirect targets no placement; the reason; and until when
 * the redirect holds because of the target placement's window, null when the window never changes
 * it again. A redirect is resolved in one hop: the URL is a page's, never another redirect's.
 */
#[Experimental]
final readonly class RedirectStep
{
    public function __construct(
        public string $url,
        public ?PlacementId $placement,
        public RedirectReason $reason,
        public ?DateTimeImmutable $validUntil,
    ) {}
}
