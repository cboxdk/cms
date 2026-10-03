<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;

/**
 * The login policy of the environment this process runs in.
 */
#[Internal]
interface LoginPolicyProbe
{
    /**
     * The name of the application's environment, such as production or local.
     */
    public function environment(): string;

    /**
     * The login policy of the environment, as `cbox-cms.identity.policy` sets it.
     *
     * @throws InvalidLoginPolicy when the setting is invalid, or may not hold in the environment
     */
    public function policy(): LoginPolicy;
}
