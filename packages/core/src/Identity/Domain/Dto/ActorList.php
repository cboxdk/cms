<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of actor.list: a page of staff actors in the order of their ids, and the id to read
 * the next page after, or null when this page is the last.
 */
#[Experimental]
final readonly class ActorList implements Result
{
    /**
     * @param  list<ListedActor>  $actors
     */
    public function __construct(
        public array $actors,
        public ?ActorId $next,
    ) {}

    /**
     * This page as a reader with $access may see it: every profile value above the access is
     * Omitted (PRD 12.2).
     */
    public function visibleTo(ClassificationAccess $access): self
    {
        return new self(array_map(static fn (ListedActor $actor): ListedActor => $actor->visibleTo($access), $this->actors), $this->next);
    }
}
