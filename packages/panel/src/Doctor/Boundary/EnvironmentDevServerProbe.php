<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\DevAddonsEnvironment;
use Cbox\Cms\Panel\Doctor\Domain\Probes\DevServerProbe;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\InvalidDevAddons;
use Illuminate\Contracts\Foundation\Application;
use Override;

/**
 * Reads CBOX_CMS_PANEL_DEV_ADDONS from this process's environment and the environment of the
 * application, as the panel's provider reads them when it boots.
 */
#[Internal]
final readonly class EnvironmentDevServerProbe implements DevServerProbe
{
    public function __construct(private Application $app) {}

    #[Override]
    public function setting(): ?string
    {
        try {
            return DevAddonsEnvironment::raw();
        } catch (InvalidDevAddons) {
            // Not text: the check reads it as set and reports what parse() says of it.
            return DevAddonsEnvironment::VARIABLE;
        }
    }

    #[Override]
    public function addons(): DevAddons
    {
        return DevAddonsEnvironment::read();
    }

    #[Override]
    public function environment(): string
    {
        return $this->app->environment();
    }
}
