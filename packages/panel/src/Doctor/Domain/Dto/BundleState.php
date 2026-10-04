<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What cms:doctor found of one addon's panel bundle on disk against the compiled registry (PRD
 * 13.4): nothing wrong, or each file that is missing or has another SHA-384 than cms:build
 * compiled, or that the process knows no directory for the bundle.
 */
#[Internal]
final readonly class BundleState
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public AddonNamespace $addon,
        public array $problems = [],
    ) {}

    public function matches(): bool
    {
        return $this->problems === [];
    }
}
