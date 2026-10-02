<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapRequest;

/**
 * Reads the arguments of cms:access:bootstrap: the UUIDv7 of the staff actor and the UUIDv7 of the
 * node. Anything else is a usage error, exit 64.
 */
#[Internal]
final readonly class AccessBootstrapInput
{
    /**
     * @throws CliCallRefused
     */
    public static function read(mixed $actor, mixed $node): BootstrapRequest
    {
        try {
            return new BootstrapRequest(
                ActorId::fromString(is_string($actor) ? $actor : ''),
                NodeId::fromString(is_string($node) ? $node : ''),
            );
        } catch (InvalidUuid7 $invalid) {
            throw CliCallRefused::usage('Give the UUIDv7 of an active staff actor and the UUIDv7 of the node it gets the bootstrap role on: cms:access:bootstrap <actor> <node>.', $invalid);
        }
    }
}
