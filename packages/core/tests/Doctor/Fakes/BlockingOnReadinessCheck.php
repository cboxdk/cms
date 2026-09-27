<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

/**
 * An addon's blocking check, addon.guard, that requires the core's partitions.runway, which only
 * affects readiness. The doctor refuses it, so a readiness failure can never skip a blocking check.
 */
final readonly class BlockingOnReadinessCheck extends FixedDoctorCheck
{
    public const string ID = 'addon.guard';

    public function __construct()
    {
        parent::__construct(self::ID, true, ['partitions.runway']);
    }
}
