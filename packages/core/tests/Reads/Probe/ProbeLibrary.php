<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Contracts\Results\ReadContent;

/**
 * What the probe action reads: the cards of the library, and how it answers. It records each
 * query it handled, so a test can see whether the action ran.
 */
final class ProbeLibrary
{
    /** @var list<Query> the queries the action handled, in order */
    public array $handled = [];

    /**
     * @param  list<ReadContent>  $cards
     * @param  'cards'|'leaky'|'count'  $answer
     */
    public function __construct(
        public array $cards = [],
        public string $answer = 'cards',
    ) {}

    public function read(ReadProbe $query): Result
    {
        $this->handled[] = $query;
        $cards = array_slice($this->cards, 0, $query->rows);

        return match ($this->answer) {
            'cards' => new ProbeCards($cards),
            'leaky' => new LeakyCards($cards),
            'count' => new ProbeCount(count($this->cards)),
        };
    }
}
