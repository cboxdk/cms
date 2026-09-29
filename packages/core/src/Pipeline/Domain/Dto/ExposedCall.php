<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Identity\TransportCredential;

/**
 * One write as an exposed surface received it (GUARDRAILS 2.1, PRD 6.1): the surface, the
 * credential the transport carried, or null when it carried none, the envelope fields the caller
 * sent, already read through the envelope's generated codec, and the command as the JSON document
 * the caller sent, with the codec of the command's name and version that reads it.
 */
#[Internal]
final readonly class ExposedCall
{
    public function __construct(
        public Surface $surface,
        public ?TransportCredential $credential,
        public RequestEnvelope $envelope,
        public CommandCodec $codec,
        public string $command,
    ) {}
}
