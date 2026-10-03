<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Core\Registry\Domain\InvalidPanelActivation;
use Cbox\Cms\Core\Registry\Domain\PanelActivation;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every implementation of PanelActivation does, run against ConfigPanelActivation and the
 * fake: it gives the activation state as it is at the call, so a change shows at the next call
 * without a rebuild, and refuses a state it cannot read.
 */
trait PanelActivationBehaviour
{
    abstract protected function activation(): PanelActivation;

    /**
     * Sets the activation state the activation() of this test reads.
     *
     * @param  list<string>  $addons
     * @param  list<string>  $contributions
     */
    abstract protected function disable(array $addons, array $contributions): void;

    /**
     * Makes the activation state unreadable.
     */
    abstract protected function breakActivation(): void;

    #[Test]
    public function nothing_is_disabled_until_the_state_says_so(): void
    {
        $this->disable([], []);

        Assert::assertTrue($this->activation()->disabled()->isEmpty());
    }

    #[Test]
    public function it_gives_the_state_as_it_is_at_each_call(): void
    {
        $activation = $this->activation();
        $this->disable(['approvals'], ['stamps.seal']);
        $first = $activation->disabled();
        $this->disable([], ['stamps.mark']);
        $second = $activation->disabled();

        Assert::assertEquals([new AddonNamespace('approvals')], $first->addons);
        Assert::assertEquals([new ContributionId('stamps.seal')], $first->contributions);
        Assert::assertSame([], $second->addons);
        Assert::assertEquals([new ContributionId('stamps.mark')], $second->contributions);
    }

    #[Test]
    public function it_refuses_a_state_it_cannot_read(): void
    {
        $this->breakActivation();

        try {
            $this->activation()->disabled();
            Assert::fail('An unreadable activation state was read.');
        } catch (InvalidPanelActivation $invalid) {
            Assert::assertNotSame('', $invalid->getMessage());
        }
    }
}
