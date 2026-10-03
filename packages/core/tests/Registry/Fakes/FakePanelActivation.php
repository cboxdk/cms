<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fakes;

use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;
use Cbox\Cms\Core\Registry\Domain\InvalidPanelActivation;
use Cbox\Cms\Core\Registry\Domain\PanelActivation;

/**
 * An activation state a test sets, or one that cannot be read. PanelActivationBehaviour holds it
 * to ConfigPanelActivation.
 */
final class FakePanelActivation implements PanelActivation
{
    private ?string $unreadable = null;

    public function __construct(private DisabledContributions $disabled = new DisabledContributions) {}

    public function set(DisabledContributions $disabled): void
    {
        $this->disabled = $disabled;
        $this->unreadable = null;
    }

    public function breakWith(string $reason): void
    {
        $this->unreadable = $reason;
    }

    public function disabled(): DisabledContributions
    {
        if ($this->unreadable !== null) {
            throw new InvalidPanelActivation($this->unreadable);
        }

        return $this->disabled;
    }
}
