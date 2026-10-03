<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Adapter\ConfigPanelActivation;
use Cbox\Cms\Core\Registry\Domain\PanelActivation;
use Illuminate\Config\Repository;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * PanelActivationBehaviour against cbox-cms.panel.disabled, read at each call.
 */
final class ConfigPanelActivationBehaviourTest extends TestCase
{
    use PanelActivationBehaviour;

    private ?Repository $config = null;

    #[Override]
    protected function activation(): PanelActivation
    {
        return new ConfigPanelActivation($this->config());
    }

    #[Override]
    protected function disable(array $addons, array $contributions): void
    {
        $this->config()->set(ConfigPanelActivation::KEY, ['addons' => $addons, 'contributions' => $contributions]);
    }

    #[Override]
    protected function breakActivation(): void
    {
        $this->config()->set(ConfigPanelActivation::KEY, ['addons' => ['App'], 'contributions' => []]);
    }

    private function config(): Repository
    {
        return $this->config ??= new Repository;
    }
}
