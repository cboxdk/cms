<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\InvalidSessionCookie;

/**
 * The session cookie this process would set, and the environment it runs in.
 */
#[Internal]
interface SessionCookieProbe
{
    /**
     * The name of the application's environment, such as production or local.
     */
    public function environment(): string;

    /**
     * The session cookie of the environment, as `cbox-cms.identity.session.cookie` sets it.
     *
     * @throws InvalidSessionCookie when the setting is invalid for the environment
     */
    public function cookie(): SessionCookie;
}
