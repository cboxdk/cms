<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * One call of a read as a surface hands it to the query pipeline (GUARDRAILS 2.1, PRD 6.2): the
 * query, the credential the transport carried, or null when it carried none, and the exposed
 * surface it came through, or null for a call from the kernel's own code. The actor is never a
 * field of the query or of anything else the caller sends: the pipeline takes it from the
 * credential alone, and a call without one reads as the anonymous principal. A surface that
 * requires an agent, MCP, reads only with an agent's credential (Surface::requiresAgent()). The
 * correlation id ties the read to the rest of its request in telemetry (PRD 6.1), when the surface
 * has one.
 */
#[Internal]
final readonly class QueryCall
{
    public function __construct(
        public Query $query,
        public ?TransportCredential $credential,
        public ?Surface $surface = null,
        public ?CorrelationId $correlationId = null,
    ) {}
}
