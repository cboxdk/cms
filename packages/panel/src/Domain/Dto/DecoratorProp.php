<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\Tighten;

/**
 * The props of its default a decorator may tighten, as its manifest declares them
 * (contributions.v1.json, `#/$defs/decorator`).
 */
#[Internal]
final readonly class DecoratorProp
{
    /**
     * @param  list<Tighten>  $tightens
     */
    public function __construct(public array $tightens) {}
}
