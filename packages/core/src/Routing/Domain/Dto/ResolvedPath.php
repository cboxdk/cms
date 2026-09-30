<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Override;

/**
 * The result of path.resolve (PRD 5.9): the outcome, the entry the placement found places, as
 * content with the node the placement sits under (the mount's source for a mount), and the
 * explanation of every step. The entry is there whenever the placement and its entry were read,
 * visible or not, so the answer carries its content keys `e-{entry}` and `n-{node}` (PRD 9.4)
 * also when the placement is not visible; it is null otherwise. It holds the fields of the entry's
 * released row only when the placement is resolved, and the query pipeline strips them to the
 * reader's classification access.
 */
#[Experimental]
final readonly class ResolvedPath implements ReadsContent
{
    public function __construct(
        public ?ReadContent $content,
        public PathExplanation $explanation,
    ) {}

    public function outcome(): ResolveOutcome
    {
        return $this->explanation->outcome;
    }

    public function resolved(): bool
    {
        return $this->explanation->outcome === ResolveOutcome::Resolved;
    }

    /**
     * @return list<ReadContent>
     */
    #[Override]
    public function contents(): array
    {
        return $this->content instanceof ReadContent ? [$this->content] : [];
    }

    /**
     * @param  list<ReadContent>  $contents
     */
    #[Override]
    public function withContents(array $contents): static
    {
        return new self($contents[0] ?? null, $this->explanation);
    }
}
