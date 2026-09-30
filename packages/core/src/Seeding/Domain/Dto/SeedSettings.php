<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The seeder's settings (cbox-cms.seeding): the service actor it writes as, or null when none is
 * named, which refuses every seed run.
 */
#[Internal]
final readonly class SeedSettings
{
    public function __construct(public ?ActorId $serviceActor) {}
}
