<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Doctor\Fakes;

use Cbox\Cms\Identity\Doctor\Domain\Probes\LoginPolicyProbe;
use Cbox\Cms\Identity\LoginPolicy\Boundary\LoginPolicyConfig;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Illuminate\Config\Repository;
use Override;

/**
 * The module's default login policy in production, with $changes merged over it as an
 * application's configuration would be, read as the identity module reads it.
 */
final readonly class FakeLoginPolicyProbe implements LoginPolicyProbe
{
    /**
     * @param  array<array-key, mixed>  $changes
     */
    public function __construct(
        public string $environment = 'production',
        public array $changes = [],
    ) {}

    #[Override]
    public function environment(): string
    {
        return $this->environment;
    }

    #[Override]
    public function policy(): LoginPolicy
    {
        /** @var array<string, mixed> $module */
        $module = require __DIR__.'/../../../config/identity.php';

        return LoginPolicyConfig::read(new Repository(['cbox-cms' => ['identity' => array_replace_recursive($module, ['policy' => $this->changes])]]), $this->environment);
    }
}
