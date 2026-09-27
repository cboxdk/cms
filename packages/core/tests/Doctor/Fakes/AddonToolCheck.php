<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

/**
 * An addon's development check, addon.tool, that needs the core's dev.node, so it can only run
 * after the core's development checks.
 */
final readonly class AddonToolCheck extends FixedDoctorCheck
{
    public const string ID = 'addon.tool';

    public function __construct()
    {
        parent::__construct(self::ID, false, ['dev.node']);
    }
}
