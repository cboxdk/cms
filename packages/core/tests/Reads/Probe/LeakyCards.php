<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\ReadContent;
use Override;

/**
 * A result that breaks ReadsContent: withContents() keeps the cards it read, fields and all, so it
 * would hand out the fields the pipeline stripped.
 */
final readonly class LeakyCards implements ReadsContent
{
    /**
     * @param  list<ReadContent>  $cards
     */
    public function __construct(
        public array $cards,
    ) {}

    #[Override]
    public function contents(): array
    {
        return $this->cards;
    }

    #[Override]
    public function withContents(array $contents): static
    {
        return $this;
    }
}
