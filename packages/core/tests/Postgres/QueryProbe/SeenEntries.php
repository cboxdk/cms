<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres\QueryProbe;

use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\ReadContent;
use Override;

/**
 * The entries a read of probe.see_entries saw, each through its home node.
 */
final readonly class SeenEntries implements ReadsContent
{
    /**
     * @param  list<ReadContent>  $entries
     */
    public function __construct(
        public array $entries,
    ) {}

    #[Override]
    public function contents(): array
    {
        return $this->entries;
    }

    #[Override]
    public function withContents(array $contents): static
    {
        return new self($contents);
    }
}
