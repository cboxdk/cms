<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of grant.list: a page of grants in the order of their ids, and the id to read the
 * next page after, or null when this page is the last.
 */
#[Experimental]
final readonly class GrantList implements Result
{
    /**
     * @param  list<ListedGrant>  $grants
     */
    public function __construct(
        public array $grants,
        public ?GrantId $next,
    ) {}

    /**
     * This page as a reader with $access may see it: every profile value above the access is
     * Omitted (PRD 12.2).
     */
    public function visibleTo(ClassificationAccess $access): self
    {
        return new self(array_map(static fn (ListedGrant $grant): ListedGrant => $grant->visibleTo($access), $this->grants), $this->next);
    }
}
