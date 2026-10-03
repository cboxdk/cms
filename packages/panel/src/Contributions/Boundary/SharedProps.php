<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The props of the contributions a panel page sends beside its own (ContributionProps): Inertia
 * props, with the deferred prop of each addon's data, which PanelPages adds to the page's props.
 */
#[Internal]
final readonly class SharedProps
{
    /**
     * @param  array<string, mixed>  $props
     */
    public function __construct(public array $props = []) {}
}
