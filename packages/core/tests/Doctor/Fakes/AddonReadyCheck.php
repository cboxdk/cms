<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

/**
 * An addon's runtime check, addon.ready, that needs a reachable Postgres and does not block.
 */
final readonly class AddonReadyCheck extends FixedDoctorCheck
{
    public const string ID = 'addon.ready';

    public function __construct()
    {
        parent::__construct(self::ID, false, ['postgres.reachable']);
    }
}
