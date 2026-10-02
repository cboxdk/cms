<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Doctor;

use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Illuminate\Config\Repository;

/**
 * The doctor's settings from the core's defaults, with the owner role the doctor knows by name.
 */
final readonly class DoctorSettingsFixture
{
    public static function settings(?string $ownerRole = 'cms_owner'): DoctorSettings
    {
        $core = require dirname(__DIR__, 3).'/core/config/cbox-cms.php';
        $config = new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => is_array($core) ? $core : []]);
        $config->set(DoctorConfig::CONFIG_KEY.'.owner_role', $ownerRole);

        return DoctorConfig::read($config, '/app');
    }
}
