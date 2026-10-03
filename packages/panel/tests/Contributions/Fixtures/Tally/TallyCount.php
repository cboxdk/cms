<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of the test addon's queries: a count, an internal summary and a confidential owner,
 * which TallyCountCodec leaves out above the reader's access.
 */
final readonly class TallyCount implements Result
{
    public function __construct(
        public int $count,
        public string $summary,
        public string $owner,
    ) {}
}
