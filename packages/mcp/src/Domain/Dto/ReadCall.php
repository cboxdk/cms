<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;

/**
 * A read an MCP tool runs: the call the query pipeline runs, and the codecs of the query, whose
 * result codec writes the answer.
 */
#[Internal]
final readonly class ReadCall
{
    public function __construct(
        public QueryCall $call,
        public QueryCodec $codec,
    ) {}
}
