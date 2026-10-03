<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A prop of a decorated default that a decorator may tighten, and only tighten: the decorators'
 * values combine most restrictively. A disabled reason can only disable (and blocks a submit, so a
 * server hook must mirror it), a description is only appended to, and a tone only moves towards
 * warning or danger.
 */
#[Experimental]
enum Tighten: string
{
    case DisabledReason = 'disabled_reason';
    case Description = 'description';
    case ToneTowardsDanger = 'tone_towards_danger';
}
