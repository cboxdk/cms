<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Rest\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;

/**
 * A read through REST as RestRequest read it: the call the query pipeline runs, and the codecs of
 * the query's name and version, whose result codec writes the answer.
 */
#[Internal]
final readonly class RestQueryInput
{
    public function __construct(
        public QueryCall $call,
        public QueryCodec $codec,
    ) {}
}
