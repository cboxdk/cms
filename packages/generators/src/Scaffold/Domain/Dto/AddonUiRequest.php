<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What cms:make:addon-ui takes: the namespace of the installed addon whose panel UI it scaffolds.
 */
#[Internal]
final readonly class AddonUiRequest
{
    public function __construct(public AddonNamespace $namespace) {}
}
