<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * One call of a read as a surface hands it to the query pipeline (GUARDRAILS 2.1, PRD 6.2): the
 * query, and the credential the transport carried, or null when it carried none. The actor is
 * never a field of the query or of anything else the caller sends: the pipeline takes it from the
 * credential alone, and a call without one reads as the anonymous principal.
 */
#[Internal]
final readonly class QueryCall
{
    public function __construct(
        public Query $query,
        public ?TransportCredential $credential,
    ) {}
}
