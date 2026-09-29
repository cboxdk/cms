<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\ReadContent;
use Override;

/**
 * The result of probe.read: the cards it read, in order.
 */
final readonly class ProbeCards implements ReadsContent
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
        return new self($contents);
    }
}
