<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;
use Cbox\Cms\Core\Registry\Domain\PanelActivation;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakePanelActivation;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * PanelActivationBehaviour against the fake the action tests use.
 */
final class FakePanelActivationBehaviourTest extends TestCase
{
    use PanelActivationBehaviour;

    private ?FakePanelActivation $activation = null;

    #[Override]
    protected function activation(): PanelActivation
    {
        return $this->fake();
    }

    #[Override]
    protected function disable(array $addons, array $contributions): void
    {
        $this->fake()->set(new DisabledContributions(
            array_map(static fn (string $addon): AddonNamespace => new AddonNamespace($addon), $addons),
            array_map(static fn (string $id): ContributionId => new ContributionId($id), $contributions),
        ));
    }

    #[Override]
    protected function breakActivation(): void
    {
        $this->fake()->breakWith('The setting cbox-cms.panel.disabled.addons names "App".');
    }

    private function fake(): FakePanelActivation
    {
        return $this->activation ??= new FakePanelActivation;
    }
}
