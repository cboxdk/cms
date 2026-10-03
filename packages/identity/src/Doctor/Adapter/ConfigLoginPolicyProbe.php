<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Doctor\Domain\Probes\LoginPolicyProbe;
use Cbox\Cms\Identity\LoginPolicy\Boundary\LoginPolicyConfig;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;

/**
 * Reads the login policy from the configuration of this process, for the application's
 * environment, as the identity module reads it when it binds the policy.
 */
#[Internal]
final readonly class ConfigLoginPolicyProbe implements LoginPolicyProbe
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
    public function policy(): LoginPolicy
    {
        return LoginPolicyConfig::read($this->config, $this->environment());
    }
}
