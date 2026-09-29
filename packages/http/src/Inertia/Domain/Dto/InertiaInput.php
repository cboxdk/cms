<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Identity\TransportCredential;

/**
 * An Inertia command request as the profile read it: the credential it carried, or null, the
 * envelope fields read through the envelope's generated codec, and the command's JSON document,
 * which the command's own codec reads once the caller's access is known.
 */
#[Internal]
final readonly class InertiaInput
{
    public function __construct(
        public ?TransportCredential $credential,
        public RequestEnvelope $envelope,
        public string $command,
    ) {}
}
