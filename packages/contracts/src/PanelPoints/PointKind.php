<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a contribution to a panel point gives the host, and so what the host does with it: a slot
 * renders components, an action renders a button that runs a command, a decorator adds around a
 * default it never receives, a replacement takes the place of a default for a key it owns, a form
 * check adds issues to a command form, a flow step runs before submit or after the receipt, an
 * observer is told after a command completed, a provider answers the command palette, a nav entry
 * and a page add to the shell, a theme sets token values, and a data point takes plain data
 * without code.
 */
#[Experimental]
enum PointKind: string
{
    case Slot = 'slot';
    case Action = 'action';
    case Nav = 'nav';
    case Page = 'page';
    case Decorator = 'decorator';
    case Replacement = 'replacement';
    case FormCheck = 'form_check';
    case FlowStep = 'flow_step';
    case Observer = 'observer';
    case Provider = 'provider';
    case Theme = 'theme';
    case Data = 'data';
}
