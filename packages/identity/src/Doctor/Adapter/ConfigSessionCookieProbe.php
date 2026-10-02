<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Doctor\Domain\Probes\SessionCookieProbe;
use Cbox\Cms\Identity\Sessions\Boundary\SessionCookieConfig;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;

/**
 * Reads the session cookie from the configuration of this process, for the application's
 * environment, as the identity module reads it when it boots.
 */
#[Internal]
final readonly class ConfigSessionCookieProbe implements SessionCookieProbe
{
    public function __construct(
        private Application $app,
        private Repository $config,
    ) {}

    #[Override]
    public function environment(): string
    {
        return $this->app->environment();
    }

    #[Override]
    public function cookie(): SessionCookie
    {
        return SessionCookieConfig::read($this->config, $this->environment());
    }
}
