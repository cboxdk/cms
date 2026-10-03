<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Providers;

use Cbox\Cms\Contracts\Build\DeclaresCoreContributions;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Closure;
use Override;

/**
 * A provider in the namespace of cboxdk/cms's modules that declares the core's contributions its
 * closure builds, so a test can declare contributions that cannot be built.
 */
final readonly class CoreContributionsProvider implements DeclaresCoreContributions
{
    /**
     * @param  Closure(): list<PanelContribution>  $contributions
     */
    public function __construct(private Closure $contributions) {}

    #[Override]
    public function coreContributions(): array
    {
        return ($this->contributions)();
    }
}
