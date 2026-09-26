<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\RegistryCacheState;

/**
 * The registry cache in bootstrap/cache/cms/ and Composer's vendor/composer/installed.json
 * (PRD 13.2).
 */
#[Internal]
interface RegistryCacheProbe
{
    public function state(): RegistryCacheState;
}
