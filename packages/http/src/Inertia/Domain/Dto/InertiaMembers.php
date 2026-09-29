<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The two members of the body of an Inertia command request, each as its own JSON document: the
 * envelope fields (envelope.v1.json) and the command.
 */
#[Internal]
final readonly class InertiaMembers
{
    public function __construct(
        public string $envelope,
        public string $command,
    ) {}
}
